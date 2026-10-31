<?php

declare(strict_types=1);

namespace App\Services\ClientAi;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Jobs\ExtractClientFileJob;
use App\Jobs\MakeCallNotesJob;
use App\Jobs\RefreshClientBriefJob;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\RecordingCipher;
use App\Services\SettingsRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Client workspaces (v1.4, P4): files and instructions, the one-page brief, notes after every call, the living memory,
 * Ask, and the "Copy for AI" bundle. Each Claude request is logged per client and checked against the workspace's
 * monthly AI budget ($5 unless changed).
 */
final class ClientWorkspace
{
    public const DEFAULT_BUDGET_USD = 5.0;

    /** Files the brief and Ask can read. */
    public const FILE_TYPES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'text/plain' => 'txt',
        'text/markdown' => 'md',
    ];

    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    /** How many passages one question carries (about 8 000 tokens). */
    private const PASSAGES = 12;

    public function __construct(
        private readonly ClientAi $ai,
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
    ) {}

    // ---- Budget and usage ----------------------------------------------------------------------------------------

    public function configured(): bool
    {
        $key = $this->vault->get('anthropic', 'api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * This month's client AI spend and limit of a workspace.
     *
     * @return array{spent_usd: float, budget_usd: float}
     */
    public function budget(int $workspaceId): array
    {
        $limit = $this->settings->get('client_ai.monthly_usd', self::DEFAULT_BUDGET_USD);
        $spent = UsageLedgerEntry::query()->withoutGlobalScopes()->where('workspace_id', $workspaceId)
            ->where('provider', 'claude')->where('use_case', 'like', 'client_%')
            ->where('occurred_at', '>=', Carbon::now($this->settings->displayTimezone())->startOfMonth()->utc())
            ->sum('cost_usd');

        return ['spent_usd' => round((float) $spent, 4), 'budget_usd' => is_numeric($limit) ? (float) $limit : self::DEFAULT_BUDGET_USD];
    }

    /** Refuses a Claude request a workspace cannot have now: no key, or the month's budget used up. */
    public function assertCanSpend(int $workspaceId): void
    {
        if (! $this->configured()) {
            throw new ApiError(409, 'ai_unavailable', 'Add your Anthropic key in API Credentials to use client notes and Ask.');
        }
        $budget = $this->budget($workspaceId);
        if ($budget['spent_usd'] >= $budget['budget_usd']) {
            throw new ApiError(403, 'ai_budget_reached', sprintf("This month's AI budget (\$%.2f) is used up. Raise it in Settings → AI.", $budget['budget_usd']));
        }
    }

    /** @param array{input_tokens: int, output_tokens: int, model: string} $usage */
    private function bill(Client $client, string $purpose, array $usage, ?string $sessionId = null): void
    {
        [$in, $out] = ClaudeClientAi::PRICES[$usage['model']] ?? ClaudeClientAi::PRICES[ClaudeClientAi::MODEL];
        UsageLedgerEntry::query()->create([
            'user_id' => $client->user_id,
            'workspace_id' => $client->workspace_id,
            'capture_session_id' => $sessionId,
            'client_id' => $client->id,
            'report_id' => 'client-ai/'.Str::ulid(),
            'source' => UsageLedgerEntry::SOURCE_SERVER,
            'use_case' => 'client_'.$purpose,
            'provider' => 'claude',
            'model' => mb_substr($usage['model'], 0, 64),
            'duration_ms' => 0,
            'channels' => 1,
            'billed_ms' => 0,
            'unit_price_usd' => 0,
            'cost_usd' => round(($usage['input_tokens'] * $in + $usage['output_tokens'] * $out) / 1_000_000, 6),
            'billable' => true,
            'occurred_at' => now(),
        ]);
    }

    /** This month's spend on one client. */
    public function clientSpend(Client $client): float
    {
        return round((float) UsageLedgerEntry::query()->withoutGlobalScopes()->where('client_id', $client->id)
            ->where('occurred_at', '>=', Carbon::now($this->settings->displayTimezone())->startOfMonth()->utc())->sum('cost_usd'), 4);
    }

    // ---- Call notes and memory -----------------------------------------------------------------------------------

    /**
     * After a final transcript is ready: queues the notes of a recording filed under a client with AI notes on.
     * Never throws (the transcript must not fail because of its notes).
     */
    public function afterTranscript(Transcript $transcript): void
    {
        try {
            if ($transcript->kind !== TranscriptKind::Final || $transcript->status !== TranscriptStatus::Completed || ! $this->configured()) {
                return;
            }
            $session = CaptureSession::query()->withoutGlobalScopes()->find($transcript->capture_session_id);
            $client = $session?->client_id !== null ? Client::query()->withoutGlobalScopes()->find($session->client_id) : null;
            if ($session === null || $client === null || ! $client->ai_notes) {
                return;
            }
            CallNote::query()->withoutGlobalScopes()->updateOrCreate(
                ['capture_session_id' => $session->id],
                ['workspace_id' => $session->workspace_id, 'client_id' => $client->id, 'status' => 'pending', 'error' => null],
            );
            MakeCallNotesJob::dispatch($session->id);
        } catch (Throwable $e) {
            Log::info('Call notes not started', ['transcript' => $transcript->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /** Writes (or rewrites) the notes of one recording, then updates the client's memory and, without files, its brief. */
    public function makeNotes(CaptureSession $session, ?string $language = null): CallNote
    {
        $client = Client::query()->withoutGlobalScopes()->findOrFail($session->client_id);
        $note = CallNote::query()->withoutGlobalScopes()->firstOrNew(['capture_session_id' => $session->id]);
        $note->fill(['workspace_id' => $session->workspace_id, 'client_id' => $client->id, 'language' => $language ?? $note->language ?? 'en']);
        $transcript = $session->finalTranscript()->first();
        $segments = $transcript?->status === TranscriptStatus::Completed ? ($transcript->segments ?? []) : [];
        if ($segments === []) {
            $note->fill(['status' => 'skipped', 'error' => 'The transcript is not ready yet.'])->save();

            return $note;
        }
        try {
            $this->assertCanSpend($session->workspace_id);
        } catch (ApiError $e) {
            $note->fill(['status' => 'skipped', 'error' => $e->getMessage()])->save();

            return $note;
        }
        $lines = [];
        foreach ($segments as $i => $segment) {
            $speaker = (string) ($segment['speaker'] ?? '');
            $lines[] = [
                'i' => $i,
                't' => self::clock((int) ($segment['start_ms'] ?? 0)),
                'speaker' => in_array($speaker, ['Me', 'me', ''], true) ? 'You' : $speaker,
                'text' => (string) ($segment['text'] ?? ''),
            ];
        }
        $brief = ClientBrief::query()->withoutGlobalScopes()->where('client_id', $client->id)->first();
        try {
            $result = $this->ai->callNotes($client->name, $brief?->toApi()['sections'] ?? [], $lines, $note->language);
        } catch (Throwable $e) {
            $note->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();

            return $note;
        }
        $this->bill($client, 'notes', $result, $session->id);
        $note->fill(['status' => 'ready', 'error' => null, 'model' => $result['model'], 'sections' => self::cleanNotes($result['sections'], $segments)])->save();
        $this->remember($note, $session);
        if ($brief?->edited !== true && ! ClientFile::query()->withoutGlobalScopes()->where('client_id', $client->id)->exists()) {
            RefreshClientBriefJob::dispatch($client->id);
        }

        return $note;
    }

    /**
     * Keeps only well-formed items whose lines exist, with the moment of their first line.
     *
     * @param  array<string, mixed>  $sections
     * @param  list<array<string, mixed>>  $segments
     * @return array<string, list<array<string, mixed>>>
     */
    public static function cleanNotes(array $sections, array $segments): array
    {
        $out = [];
        foreach (array_keys(CallNote::SECTIONS) as $key) {
            $items = [];
            foreach (is_array($sections[$key] ?? null) ? $sections[$key] : [] as $item) {
                $text = is_array($item) && is_string($item['text'] ?? null) ? trim($item['text']) : '';
                $lines = array_values(array_filter(
                    array_map('intval', is_array($item['lines'] ?? null) ? $item['lines'] : []),
                    fn (int $i): bool => isset($segments[$i]),
                ));
                // An item that points at no real line is not in the transcript: it is dropped, never shown.
                if ($text === '' || $lines === []) {
                    continue;
                }
                $clean = [
                    'text' => mb_substr($text, 0, 300),
                    'verify' => (bool) ($item['verify'] ?? false),
                    'lines' => array_slice($lines, 0, 10),
                    'at_ms' => (int) ($segments[$lines[0]]['start_ms'] ?? 0),
                ];
                if ($key === 'changes') {
                    $clean['kind'] = in_array($item['kind'] ?? null, ['add', 'remove', 'modify'], true) ? $item['kind'] : 'modify';
                }
                $items[] = $clean;
            }
            $out[$key] = $items;
        }

        return $out;
    }

    /** The memory lines of one call: its decisions, changes and open points, dated and citing their moment. */
    private function remember(CallNote $note, CaptureSession $session): void
    {
        // Running the notes again replaces what they added before; lines a person pinned stay.
        ClientMemoryItem::query()->withoutGlobalScopes()->where('client_id', $note->client_id)
            ->where('source_type', 'call')->where('source_id', $session->id)->where('pinned', false)->delete();
        $day = $session->started_at->copy()->setTimezone($this->settings->displayTimezone())->toDateString();
        $kinds = ['decisions' => 'decision', 'changes' => 'change', 'to_confirm' => 'question'];
        foreach ($kinds as $section => $kind) {
            foreach ($note->sections[$section] ?? [] as $item) {
                $prefix = $kind === 'change' ? ['add' => 'Added: ', 'remove' => 'Removed: ', 'modify' => 'Changed: '][$item['kind'] ?? 'modify'] : '';
                ClientMemoryItem::query()->withoutGlobalScopes()->create([
                    'workspace_id' => $note->workspace_id,
                    'client_id' => $note->client_id,
                    'kind' => $kind,
                    'text' => mb_substr($prefix.$item['text'].(($item['verify'] ?? false) ? ' (verify)' : ''), 0, 500),
                    'happened_on' => $day,
                    'source_type' => 'call',
                    'source_id' => $session->id,
                    'at_ms' => $item['at_ms'] ?? null,
                ]);
            }
        }
    }

    // ---- Brief -----------------------------------------------------------------------------------------------------

    /**
     * Makes the brief from the client's files, or from its call notes when it has none. An edited brief is kept
     * unless [$force] (the person asked for a new one).
     */
    public function refreshBrief(Client $client, bool $force = false): ClientBrief
    {
        $brief = ClientBrief::query()->withoutGlobalScopes()->firstOrNew(['client_id' => $client->id]);
        $brief->workspace_id = $client->workspace_id;
        if ($brief->exists && $brief->edited && ! $force) {
            return $brief;
        }
        $files = ClientFile::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('status', 'ready')->orderBy('created_at')->get();
        $documents = [];
        foreach ($files as $file) {
            $pdf = $file->mime === 'application/pdf' && $file->path !== null ? $this->original($file) : null;
            $documents[] = $pdf !== null ? ['title' => $file->name, 'pdf' => $pdf] : ['title' => $file->name, 'text' => (string) $file->text];
        }
        $notes = $documents === [] ? $this->recentNotesText($client, 15) : [];
        if ($documents === [] && $notes === []) {
            $brief->fill(['sections' => ClientBrief::emptySections(), 'status' => 'ready', 'error' => null])->save();

            return $brief;
        }
        try {
            $this->assertCanSpend($client->workspace_id);
            $result = $this->ai->brief($client->name, $documents, $notes);
        } catch (Throwable $e) {
            $brief->fill(['sections' => $brief->sections ?? ClientBrief::emptySections(), 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();

            return $brief;
        }
        $this->bill($client, 'brief', $result);
        $brief->fill([
            'sections' => ClientBrief::clean($result['sections']),
            'status' => 'ready',
            'error' => null,
            'edited' => false,
            'model' => $result['model'],
            'generated_at' => now(),
        ])->save();

        return $brief;
    }

    /** @return list<string> the latest ready notes of a client as plain text, oldest first */
    private function recentNotesText(Client $client, int $count): array
    {
        $notes = CallNote::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('status', 'ready')
            ->with('captureSession')->latest()->limit($count)->get()->reverse();

        return array_values($notes->map(fn (CallNote $note): string => $this->noteText($note))->all());
    }

    /** One call's notes as plain text (the bundle, the brief from calls). */
    public function noteText(CallNote $note): string
    {
        $session = $note->captureSession;
        $title = ($session?->title ?: 'Call').' · '.($session?->started_at->copy()->setTimezone($this->settings->displayTimezone())->format('M j, Y') ?? '');
        $lines = ["### {$title}"];
        foreach ($note->toApi()['sections'] as $section) {
            $lines[] = $section['heading'].':';
            foreach ($section['items'] as $item) {
                $lines[] = '- '.(isset($item['kind']) ? ucfirst((string) $item['kind']).': ' : '').$item['text']
                    .' ['.self::clock((int) ($item['at_ms'] ?? 0)).']'.(($item['verify'] ?? false) ? ' (verify)' : '');
            }
        }

        return implode("\n", $lines);
    }

    // ---- Files -----------------------------------------------------------------------------------------------------

    /** Stores an uploaded file encrypted and reads its text on the queue. */
    public function addFile(Client $client, UploadedFile $upload, int $userId): ClientFile
    {
        $mime = self::fileType($upload->getClientMimeType(), $upload->getClientOriginalName());
        if ($mime === null) {
            throw new ApiError(422, 'validation_failed', 'Add a PDF, Word (.docx), text or Markdown file.', ['errors' => ['file' => ['Add a PDF, Word (.docx), text or Markdown file.']]]);
        }
        $file = ClientFile::query()->withoutGlobalScopes()->create([
            'workspace_id' => $client->workspace_id,
            'user_id' => $userId,
            'client_id' => $client->id,
            'name' => mb_substr($upload->getClientOriginalName() ?: 'File', 0, 200),
            'mime' => $mime,
            'size_bytes' => (int) $upload->getSize(),
            'status' => 'extracting',
        ]);
        $relative = 'client-files/'.$client->id.'/'.$file->id.'.elr';
        $disk = Storage::disk('recordings');
        $disk->makeDirectory(dirname($relative));
        app(RecordingCipher::class)->encryptFile((string) $upload->getRealPath(), $disk->path($relative), $file->id);
        $file->forceFill(['path' => $relative])->save();
        ExtractClientFileJob::dispatch($file->id);

        return $file;
    }

    /** Pasted instructions: kept as text only, ready at once. */
    public function addInstructions(Client $client, string $text, ?string $name, int $userId): ClientFile
    {
        $file = ClientFile::query()->withoutGlobalScopes()->create([
            'workspace_id' => $client->workspace_id,
            'user_id' => $userId,
            'client_id' => $client->id,
            'name' => mb_substr(trim((string) $name) ?: 'Instructions', 0, 200),
            'mime' => 'text/plain',
            'size_bytes' => strlen($text),
            'status' => 'ready',
            'text' => $text,
        ]);
        $this->briefAfterFiles($client);

        return $file;
    }

    /** Reads a stored file's text (PDFs through Claude Haiku, Word and text files here), then refreshes the brief. */
    public function extract(ClientFile $file): void
    {
        $client = Client::query()->withoutGlobalScopes()->find($file->client_id);
        $bytes = $this->original($file);
        if ($client === null || $bytes === null) {
            $file->forceFill(['status' => 'failed', 'error' => 'The file could not be read.'])->save();

            return;
        }
        try {
            if ($file->mime === 'application/pdf') {
                $this->assertCanSpend($file->workspace_id);
                $result = $this->ai->pdfText($bytes, $file->name);
                $this->bill($client, 'file', $result);
                $file->forceFill(['text' => $result['text'], 'pages' => $result['pages']]);
            } elseif ($file->mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                $file->forceFill(['text' => self::docxText($bytes)]);
            } else {
                $file->forceFill(['text' => mb_convert_encoding($bytes, 'UTF-8', 'UTF-8')]);
            }
            $file->forceFill(['status' => 'ready', 'error' => null])->save();
        } catch (Throwable $e) {
            $file->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();

            return;
        }
        $this->briefAfterFiles($client);
    }

    private function briefAfterFiles(Client $client): void
    {
        $brief = ClientBrief::query()->withoutGlobalScopes()->where('client_id', $client->id)->first();
        if ($brief?->edited !== true) {
            RefreshClientBriefJob::dispatch($client->id);
        }
    }

    /** Deletes a file and its encrypted original. */
    public function deleteFile(ClientFile $file): void
    {
        if ($file->path !== null) {
            Storage::disk('recordings')->delete($file->path);
        }
        $file->delete();
    }

    private function original(ClientFile $file): ?string
    {
        if ($file->path === null || ! Storage::disk('recordings')->exists($file->path)) {
            return null;
        }
        $plain = app(RecordingCipher::class)->decryptToMemory(Storage::disk('recordings')->path($file->path), $file->id);
        $plain->rewind();
        $bytes = '';
        while (! $plain->eof()) {
            $bytes .= $plain->fread(1 << 20);
        }

        return $bytes;
    }

    /** The type a file is kept as, from its declared type or its name; null for something it cannot read. */
    public static function fileType(?string $mime, string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        foreach (self::FILE_TYPES as $type => $extension) {
            if ($mime === $type || $ext === $extension) {
                return $type;
            }
        }

        return $ext === 'markdown' ? 'text/markdown' : null;
    }

    /** The words of a Word file: its paragraphs, one per line. */
    public static function docxText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'el-docx-');
        if ($path === false) {
            throw new RuntimeException('Could not read the Word file.');
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('This Word file could not be opened.');
            }
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }
        $xml = (string) preg_replace(['~</w:p>~', '~<w:tab/>~', '~<w:br/>~'], ["\n", "\t", "\n"], $xml);

        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    // ---- Ask and the bundle ----------------------------------------------------------------------------------------

    /**
     * Answers a question about a client from its brief, memory, files and transcripts, with citations (a call and
     * its moment, or a file and its page).
     *
     * @return array{answer: string, citations: list<array<string, mixed>>}
     */
    public function ask(Client $client, string $question, string $language): array
    {
        $this->assertCanSpend($client->workspace_id);
        $passages = (new PassageSearch($this->settings))->top($client, $question, self::PASSAGES);
        $brief = ClientBrief::query()->withoutGlobalScopes()->where('client_id', $client->id)->first();
        $memory = ClientMemoryItem::query()->withoutGlobalScopes()->where('client_id', $client->id)->orderByDesc('pinned')->orderBy('happened_on')
            ->limit(200)->get()->map(fn (ClientMemoryItem $m): string => '- '.ucfirst($m->kind).($m->happened_on ? ' ('.$m->happened_on->toDateString().')' : '').': '.$m->text)->all();
        try {
            $result = $this->ai->ask(
                $client->name,
                $brief?->toApi()['sections'] ?? ClientBrief::emptySections(),
                array_values($memory),
                array_map(fn (array $p): array => ['title' => $p['title'], 'blocks' => $p['blocks']], $passages),
                $question,
                $language,
            );
        } catch (Throwable $e) {
            throw new ApiError(502, 'provider_error', 'Claude could not answer right now. Try again in a minute.');
        }
        $this->bill($client, 'ask', $result);
        $citations = [];
        foreach ($result['citations'] as $citation) {
            $passage = $passages[$citation['passage']] ?? null;
            $where = $passage['where'][$citation['block']] ?? $passage['where'][0] ?? null;
            if ($passage === null || $where === null) {
                continue;
            }
            $entry = $passage['kind'] === 'call'
                ? ['type' => 'call', 'session_id' => $passage['id'], 'title' => $passage['label'], 'at_ms' => $where, 'quote' => $citation['quote']]
                : ['type' => 'file', 'file_id' => $passage['id'], 'title' => $passage['label'], 'page' => $where, 'quote' => $citation['quote']];
            $citations[json_encode([$entry['type'], $passage['id'], $where])] = $entry;
        }

        return ['answer' => $result['answer'], 'citations' => array_values($citations)];
    }

    /** "Copy for AI": the brief, the memory and the last [$notes] call notes, ready to paste into a chat project. */
    public function bundle(Client $client, int $notes = 5): string
    {
        $brief = ClientBrief::query()->withoutGlobalScopes()->where('client_id', $client->id)->first()?->toApi()['sections'] ?? ClientBrief::emptySections();
        $out = ["# {$client->name}: project context", '', '## Brief'];
        $out[] = 'Goal: '.($brief['goal'] !== '' ? $brief['goal'] : '(not set)');
        foreach (array_slice(ClientBrief::SECTIONS, 1) as $key) {
            if ($brief[$key] !== []) {
                $out[] = ucfirst($key).':';
                foreach ($brief[$key] as $line) {
                    $out[] = '- '.$line;
                }
            }
        }
        $memory = ClientMemoryItem::query()->withoutGlobalScopes()->where('client_id', $client->id)->orderBy('kind')->orderBy('happened_on')->get();
        if ($memory->isNotEmpty()) {
            $out[] = '';
            $out[] = '## Memory';
            foreach ($memory as $item) {
                $out[] = '- '.ucfirst($item->kind).($item->happened_on ? ' ('.$item->happened_on->toDateString().')' : '').': '.$item->text.($item->pinned ? ' [pinned]' : '');
            }
        }
        $recent = $this->recentNotesText($client, max(0, min(20, $notes)));
        if ($recent !== []) {
            $out[] = '';
            $out[] = '## Recent call notes';
            foreach ($recent as $text) {
                $out[] = '';
                $out[] = $text;
            }
        }

        return implode("\n", $out)."\n";
    }

    /** 754 000 ms → "12:34", 4 000 000 ms → "1:06:40". */
    public static function clock(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}

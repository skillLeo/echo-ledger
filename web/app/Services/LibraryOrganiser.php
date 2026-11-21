<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Exceptions\ApiError;
use App\Filament\Support\ConsoleV2;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\LabelColor;
use App\Models\SessionLabel;
use App\Models\User;
use App\Services\Callers\CallerMatcher;
use App\Services\Titles\AutoTitles;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\Workspaces;
use App\Support\Brand;
use App\Support\Translation\IsolatingTranslator;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Organising the library (plan B15): smart views, labels, pins, moving recordings between client folders and bulk
 * actions. Every change touches the session's `updated_at`, so devices pick it up on their next library sync.
 */
final class LibraryOrganiser
{
    public const VIEWS = ['today', 'this_week', 'waiting', 'bookmarked', 'failed', 'pinned'];

    /**
     * Smart folders (since v1.5): computed views of the library, never copies. `shared` is what others sent the
     * asking person, the rest are recordings of the workspace.
     */
    public const FOLDERS = ['inbox', 'whatsapp', 'whatsapp_business', 'google_meet', 'zoom', 'teams', 'phone', 'voice_notes', 'imported', 'shared'];

    /** The apps each app folder holds (WhatsApp on the desktop's browser counts as WhatsApp). */
    public const FOLDER_APPS = [
        'whatsapp' => [SourceApp::WhatsApp, SourceApp::WhatsAppWeb],
        'whatsapp_business' => [SourceApp::WhatsAppBusiness],
        'google_meet' => [SourceApp::GoogleMeet],
        'zoom' => [SourceApp::Zoom],
        'teams' => [SourceApp::MicrosoftTeams],
        'phone' => [SourceApp::PhoneCall],
    ];

    /** Files that came in as files rather than being recorded. */
    public const FILE_ORIGINS = [CaptureSession::ORIGIN_IMPORTED, CaptureSession::ORIGIN_REQUESTED];

    public const BULK_ACTIONS = ['move', 'label', 'unlabel', 'pin', 'unpin', 'keep', 'unkeep', 'important', 'unimportant', 'delete'];

    /** Since 2.0.0-h (parity plan 3) also html, as one recording's export already has. */
    public const EXPORT_FORMATS = ['txt', 'md', 'srt', 'vtt', 'json', 'docx', 'html'];

    public const MAX_LABELS = 20;

    public const MAX_LABEL_CHARS = 40;

    public const MAX_BULK = 200;

    public function __construct(private readonly TranscriptExporter $exporter, private readonly SettingsRepository $settings) {}

    /** A label as stored: single spaces, no control characters, at most 40 characters; null when nothing is left. */
    public static function cleanLabel(mixed $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', '', $label)));

        return $clean === '' || mb_strlen($clean) > self::MAX_LABEL_CHARS ? null : $clean;
    }

    /**
     * Restricts the query to a smart view. Today and This week are calendar ranges in [zone] (weeks start on Monday);
     * Waiting is a recording whose phone or computer has not decided Keep or Discard yet; Failed is a failed session
     * or an upload that failed.
     *
     * @param  Builder<CaptureSession>  $query
     * @return Builder<CaptureSession>
     */
    public function applyView(Builder $query, string $view, ?string $zone = null): Builder
    {
        $zone ??= $this->settings->displayTimezone();
        $now = Carbon::now($zone);
        $column = $query->qualifyColumn('started_at');

        return match ($view) {
            'today' => $query->where($column, '>=', $now->copy()->startOfDay()->utc())->where($column, '<', $now->copy()->startOfDay()->addDay()->utc()),
            'this_week' => $query->where($column, '>=', $now->copy()->startOfWeek(Carbon::MONDAY)->utc())
                ->where($column, '<', $now->copy()->startOfWeek(Carbon::MONDAY)->addWeek()->utc()),
            'waiting' => $query->where($query->qualifyColumn('status'), SessionStatus::Recording->value),
            'bookmarked' => $query->whereHas('markers'),
            'failed' => $query->where(fn (Builder $q) => $q->where($q->qualifyColumn('status'), SessionStatus::Failed->value)
                ->orWhereHas('recordings', fn (Builder $r) => $r->where('upload_status', UploadStatus::Failed->value))),
            'pinned' => $query->where($query->qualifyColumn('pinned'), true),
            // Since v2.0 (the v2.0 Library's saved view): recordings kept with audio but no finished transcript yet.
            // Since v1.8 audio-only recordings too (kept as audio, transcribed when the person asks).
            'untranscribed' => $query->whereIn($query->qualifyColumn('status'), [SessionStatus::Completed->value, SessionStatus::AudioOnly->value])
                ->whereDoesntHave('finalTranscript', fn (Builder $t): Builder => $t->where('status', TranscriptStatus::Completed->value)),
            default => $query,
        };
    }

    /** How many of the workspace's recordings have no finished transcript (the v2.0 Library's Untranscribed). */
    public function untranscribedCount(int $workspaceId, ?string $zone = null): int
    {
        return $this->applyView(CaptureSession::query()->where('workspace_id', $workspaceId)->ofPerson(), 'untranscribed', $zone)->count();
    }

    /**
     * How many recordings each smart view holds right now, counted like the library lists them: a recording still
     * running only shows under Waiting.
     *
     * @return array<string, int>
     */
    public function counts(int $workspaceId, ?string $zone = null): array
    {
        $conditions = [];
        foreach (self::VIEWS as $view) {
            $conditions[$view] = fn (Builder $q): Builder => $this->applyView(
                $view === 'waiting' ? $q : $q->where($q->qualifyColumn('status'), '!=', SessionStatus::Recording->value), $view, $zone);
        }

        return $this->tally($workspaceId, $conditions);
    }

    /**
     * Counts the person's recordings in the workspace that meet each condition, in one pass over them (plan B6: one
     * query instead of one a condition). Each condition is the very filter the library lists with, compiled into a
     * `SUM(CASE WHEN …)`, so a count always matches its list.
     *
     * @param  array<string, Closure(Builder<CaptureSession>): Builder<CaptureSession>>  $conditions
     * @return array<string, int>
     */
    private function tally(int $workspaceId, array $conditions): array
    {
        $query = CaptureSession::query()->where('workspace_id', $workspaceId)->ofPerson();
        $grammar = $query->getQuery()->getGrammar();
        foreach ($conditions as $name => $condition) {
            $only = $condition(CaptureSession::query()->withoutGlobalScopes())->getQuery();
            // A condition that filters nothing (a view or folder with no rule) counts every recording.
            $where = trim((string) preg_replace('/^where /', '', $grammar->compileWheres($only))) ?: '1 = 1';
            $query->selectRaw('COALESCE(SUM(CASE WHEN '.$where.' THEN 1 ELSE 0 END), 0) as '.$grammar->wrap($name), $only->getBindings());
        }
        $row = (array) $query->toBase()->first();

        return array_map(fn ($n): int => (int) $n, array_intersect_key($row, $conditions));
    }

    /**
     * Restricts the query to a smart folder of the workspace (since v1.5): the Inbox (no project), one app's calls,
     * SIM calls, voice notes recorded on a device, or files imported, uploaded or sent through a request link.
     * `shared` is not the workspace's: it lists nothing here.
     *
     * @param  Builder<CaptureSession>  $query
     * @return Builder<CaptureSession>
     */
    public function applyFolder(Builder $query, string $folder): Builder
    {
        $app = $query->qualifyColumn('source_app');
        $origin = $query->qualifyColumn('origin');

        return match ($folder) {
            'inbox' => $query->whereNull($query->qualifyColumn('client_id')),
            'voice_notes' => $query->where($app, SourceApp::VoiceNote->value)
                ->where(fn (Builder $q) => $q->whereNull($origin)->orWhereNotIn($origin, self::FILE_ORIGINS)),
            'imported' => $query->whereIn($origin, self::FILE_ORIGINS),
            'shared' => $query->whereRaw('1 = 0'),
            default => array_key_exists($folder, self::FOLDER_APPS)
                ? $query->whereIn($app, array_map(fn (SourceApp $a): string => $a->value, self::FOLDER_APPS[$folder]))
                : $query,
        };
    }

    /**
     * How many recordings each smart folder of the workspace holds, counted like the library lists them (recordings
     * still running are left out). `shared` is not the workspace's: it is 0 here.
     *
     * @return array<string, int>
     */
    public function folderCounts(int $workspaceId): array
    {
        $conditions = [];
        foreach (self::FOLDERS as $folder) {
            if ($folder !== 'shared') {
                $conditions[$folder] = fn (Builder $q): Builder => $this->applyFolder($q->where($q->qualifyColumn('status'), '!=', SessionStatus::Recording->value), $folder);
            }
        }
        $counts = $this->tally($workspaceId, $conditions);

        // In the order of FOLDERS, `shared` (others' recordings) counted by Teammates.
        return array_map(fn (string $folder): int => $counts[$folder] ?? 0, array_combine(self::FOLDERS, self::FOLDERS));
    }

    /** How many of the workspace's recordings wait for Keep or Discard (the Library's chip, since v1.5). */
    public function waitingCount(int $workspaceId): int
    {
        return $this->applyView(CaptureSession::query()->where('workspace_id', $workspaceId)->ofPerson(), 'waiting', $this->settings->displayTimezone())->count();
    }

    /**
     * The one-line summary of the workspace's recordings waiting for Keep or Discard, worded like the phone and
     * desktop cards: "4 calls waiting · oldest Oct 30 · 2:18 total", or "1 call waiting · Oct 30 · 0:42 total". The
     * date is the oldest start in [zone]; the total adds up the stored lengths. Null when nothing is waiting.
     */
    public function waitingSummary(int $workspaceId, ?string $zone = null): ?string
    {
        $zone ??= $this->settings->displayTimezone();
        $stats = $this->applyView(CaptureSession::query()->where('workspace_id', $workspaceId)->ofPerson(), 'waiting', $zone)->toBase()
            ->selectRaw('COUNT(*) AS waiting, MIN(started_at) AS oldest, SUM(duration_ms) AS total_ms')->first();
        $count = (int) ($stats->waiting ?? 0);
        if ($stats === null || $count === 0) {
            return null;
        }
        $oldest = Carbon::parse((string) $stats->oldest, (string) config('app.timezone'))->setTimezone($zone)->format('M j');
        // An open session has no length until its device finishes it: the total is left out while none is known.
        $totalMs = (int) ($stats->total_ms ?? 0);
        $total = $totalMs > 0 ? ' · '.TranscriptExporter::clock($totalMs).' total' : '';

        return $count === 1
            ? "1 call waiting · {$oldest}{$total}"
            : "{$count} calls waiting · oldest {$oldest}{$total}";
    }

    /**
     * Every label the person uses in the workspace (all of the workspace's when no person is known), with how many
     * recordings carry it and, since 2.0.0-i, its colour (null until one is chosen).
     *
     * @return list<array{label: string, count: int, color: string|null}>
     */
    public function labels(int $workspaceId): array
    {
        $userId = CurrentWorkspace::userId();
        $colors = $userId !== null ? $this->labelColors($workspaceId, $userId) : [];

        return SessionLabel::query()
            ->whereHas('captureSession', fn (Builder $q) => $q->where('workspace_id', $workspaceId)->ofPerson())
            ->selectRaw('label, COUNT(*) as aggregate')->groupBy('label')->orderBy('label')->get()
            ->map(fn (SessionLabel $l): array => ['label' => $l->label, 'count' => (int) $l->getAttribute('aggregate'),
                'color' => $colors[mb_strtolower($l->label)] ?? null])
            ->values()->all();
    }

    /**
     * One person's label colours in a workspace (since 2.0.0-i), label in lower case => "#rrggbb", read once a request.
     *
     * @return array<string, string>
     */
    public function labelColors(int $workspaceId, int $userId): array
    {
        $key = self::colorsKey($workspaceId, $userId);
        $cached = request()->attributes->get($key);
        if (is_array($cached)) {
            /** @var array<string, string> $cached */
            return $cached;
        }
        $colors = LabelColor::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)
            // Lower case on read too, so the apps' palette matches whatever wrote the row.
            ->pluck('color', 'label_key')->map(fn ($c): string => strtolower((string) $c))->all();
        request()->attributes->set($key, $colors);

        return $colors;
    }

    /**
     * Sets or clears the colour of one of the person's labels (PUT /api/labels/color, since 2.0.0-i). Their recordings
     * that carry it get a new updated_at so the other devices sync the colour. Returns the label as the recordings spell
     * it and the colour; a label none of their recordings carries is 404 label_not_found.
     *
     * @return array{label: string, color: string|null}
     */
    public function setLabelColor(int $workspaceId, int $userId, mixed $label, ?string $color): array
    {
        $clean = self::cleanLabel($label);
        if ($clean === null) {
            throw new ApiError(422, 'validation_failed', 'The label is required.', ['errors' => ['label' => ['The label is required.']]]);
        }
        $key = mb_strtolower($clean);
        // The person's labels, compared in PHP: SQL's LOWER() and the column's collation disagree on non-English letters
        // ("Été", accents, Arabic marks) from one database to the next.
        $carriers = SessionLabel::query()
            ->join('capture_sessions', 'capture_sessions.id', '=', 'session_labels.capture_session_id')
            ->where('capture_sessions.workspace_id', $workspaceId)->where('capture_sessions.user_id', $userId)
            ->whereNull('capture_sessions.deleted_at')
            ->orderBy('session_labels.id')
            ->get(['session_labels.capture_session_id', 'session_labels.label'])
            ->filter(fn (SessionLabel $l): bool => mb_strtolower((string) $l->label) === $key);
        if ($carriers->isEmpty()) {
            throw new ApiError(404, 'label_not_found', 'No recording has this label.');
        }
        $spelt = (string) $carriers->firstOrFail()->label;
        $color = $color !== null ? strtolower($color) : null;
        DB::transaction(function () use ($workspaceId, $userId, $key, $color, $carriers): void {
            $row = ['workspace_id' => $workspaceId, 'user_id' => $userId, 'label_key' => $key];
            if ($color === null) {
                LabelColor::query()->where($row)->delete();
            } else {
                // One statement, so two devices setting it at once never trip the unique index.
                LabelColor::query()->upsert([[...$row, 'color' => $color, 'created_at' => now(), 'updated_at' => now()]],
                    ['workspace_id', 'user_id', 'label_key'], ['color', 'updated_at']);
            }
            // A plain list of ids: MySQL refuses an UPDATE that reads its own table in a subquery (error 1093).
            foreach ($carriers->pluck('capture_session_id')->unique()->chunk(500) as $ids) {
                CaptureSession::query()->whereIn('id', $ids->values()->all())->update(['updated_at' => now()]);
            }
        });
        request()->attributes->remove(self::colorsKey($workspaceId, $userId));

        return ['label' => $spelt, 'color' => $color];
    }

    private static function colorsKey(int $workspaceId, int $userId): string
    {
        return 'el.label-colors.'.$workspaceId.'.'.$userId;
    }

    /**
     * Applies a single-recording change: title, client folder, pin and the full list of labels.
     *
     * @param  array{title?: string|null, client_id?: string|null, pinned?: bool, keep?: bool, important?: bool, labels?: list<mixed>}  $data
     */
    public function update(CaptureSession $session, array $data): CaptureSession
    {
        $askTitle = false;
        DB::transaction(function () use ($session, $data, &$askTitle): void {
            $learn = false;
            if (array_key_exists('client_id', $data)) {
                $before = $session->client_id;
                $session->client_id = $this->ownedClient($session->workspace_id, $data['client_id'])?->id;
                $learn = $session->client_id !== null && $session->client_id !== $before;
            }
            // Since 2.0.0-k: "Call with …" sent back as it is shown (an app's pre-filled rename box) changes nothing: it
            // stays the person called's title, never the person's own.
            $shown = array_map(IsolatingTranslator::strip(...), array_filter(array_map(
                fn (string $language): ?string => $session->personTitle($language), array_keys(ConsoleV2::LANGUAGES))));
            if (array_key_exists('title', $data) && ! in_array(IsolatingTranslator::strip(trim((string) $data['title'])), $shown, true)) {
                $title = trim((string) $data['title']);
                $session->title = $title === '' ? null : $title;
                // A person's title is never replaced; clearing it asks for the automatic one again (since v1.5, Phase 1C).
                // Since 2.0.0-k it always wins, even a generic word ("WhatsApp call"): title_by_person.
                $session->title_auto = false;
                $session->title_by_person = $session->title !== null;
                $askTitle = $session->title === null;
            }
            if (array_key_exists('pinned', $data)) {
                $session->pinned = (bool) $data['pinned'];
            }
            if (array_key_exists('keep', $data)) {
                $session->keep_forever = (bool) $data['keep'];
            }
            if (array_key_exists('important', $data)) {
                $session->important = (bool) $data['important'];
            }
            if (array_key_exists('labels', $data)) {
                $this->replaceLabels($session, $data['labels']);
            }
            $session->updated_at = now();
            $session->save();
            // Caller names: the person on this recording becomes a contact of the client it was moved to.
            if ($learn) {
                app(CallerMatcher::class)->learn($session);
            }
        });
        if ($askTitle) {
            app(AutoTitles::class)->ask($session);
        }

        return $session->refresh();
    }

    /** @param list<mixed> $labels */
    private function replaceLabels(CaptureSession $session, array $labels): void
    {
        $clean = [];
        foreach ($labels as $label) {
            $value = self::cleanLabel($label);
            if ($value !== null && ! in_array(mb_strtolower($value), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $value;
            }
        }
        if (count($clean) > self::MAX_LABELS) {
            throw new ApiError(422, 'too_many_labels', 'A recording can have at most '.self::MAX_LABELS.' labels.', ['errors' => ['labels' => ['Too many labels.']]]);
        }
        $session->labels()->delete();
        foreach ($clean as $label) {
            $session->labels()->create(['label' => $label]);
        }
    }

    /**
     * Applies one action to many recordings of the workspace; ids that are not its own are ignored. Returns how many
     * recordings were changed.
     *
     * @param  list<string>  $ids
     */
    public function bulk(int $workspaceId, array $ids, string $action, ?string $clientId = null, ?string $label = null, ?User $actor = null): int
    {
        $sessions = CaptureSession::query()->where('workspace_id', $workspaceId)->ofPerson()->whereIn('id', array_slice(array_values(array_unique($ids)), 0, self::MAX_BULK))->get();
        if ($action === 'delete' && $actor !== null) {
            // A member deletes only what they recorded; the rest is skipped like a foreign id (B20 roles).
            $sessions = $sessions->filter(fn (CaptureSession $s): bool => app(Workspaces::class)->canDeleteSession($actor, $s))->values();
        }
        if ($sessions->isEmpty()) {
            return 0;
        }
        $label = in_array($action, ['label', 'unlabel'], true) ? self::cleanLabel($label) : null;
        if (in_array($action, ['label', 'unlabel'], true) && $label === null) {
            throw new ApiError(422, 'validation_failed', 'The label is required.', ['errors' => ['label' => ['The label is required.']]]);
        }
        $client = $action === 'move' ? $this->ownedClient($workspaceId, $clientId) : null;

        DB::transaction(function () use ($sessions, $action, $client, $label): void {
            foreach ($sessions as $session) {
                match ($action) {
                    'move' => $session->client_id = $client?->id,
                    'pin' => $session->pinned = true,
                    'unpin' => $session->pinned = false,
                    'keep' => $session->keep_forever = true,
                    'unkeep' => $session->keep_forever = false,
                    'important' => $session->important = true,
                    'unimportant' => $session->important = false,
                    'label' => $this->addLabel($session, (string) $label),
                    'unlabel' => $session->labels()->whereRaw('LOWER(label) = ?', [mb_strtolower((string) $label)])->delete(),
                    default => null,
                };
                if ($action === 'delete') {
                    $session->delete();

                    continue;
                }
                $session->updated_at = now();
                $session->save();
            }
        });

        return $sessions->count();
    }

    private function addLabel(CaptureSession $session, string $label): void
    {
        $existing = $session->labels()->pluck('label')->map(fn ($l): string => mb_strtolower((string) $l))->all();
        if (in_array(mb_strtolower($label), $existing, true)) {
            return;
        }
        if (count($existing) >= self::MAX_LABELS) {
            throw new ApiError(422, 'too_many_labels', 'A recording can have at most '.self::MAX_LABELS.' labels.', ['errors' => ['label' => ['Too many labels.']]]);
        }
        $session->labels()->create(['label' => $label]);
    }

    /** The workspace's client, or null for "no client"; another workspace's or a missing client is 404 client_not_found. */
    private function ownedClient(int $workspaceId, mixed $clientId): ?Client
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }
        $client = Client::query()->where('workspace_id', $workspaceId)->ofPerson()->find((string) $clientId);
        if (! $client instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Project not found.');
        }

        return $client;
    }

    /**
     * The finished transcripts of the chosen recordings in one zip file. Recordings without a finished transcript
     * are skipped; when none has one, 409 `nothing_to_export`. With a Show as `$view` (roman, urdu, english) each
     * transcript whose view is ready is exported in it; the others keep their original words.
     *
     * @param  list<string>  $ids
     * @return array{body: string, filename: string, count: int}
     */
    public function exportZip(int $workspaceId, array $ids, string $format, ?string $view = null): array
    {
        $sessions = CaptureSession::query()->where('workspace_id', $workspaceId)->ofPerson()->whereIn('id', array_slice(array_values(array_unique($ids)), 0, self::MAX_BULK))
            ->with(['finalTranscript', 'client'])->orderBy('started_at')->get();
        $path = tempnam(sys_get_temp_dir(), 'el-export-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the zip file.');
            }
            $names = [];
            foreach ($sessions as $session) {
                $transcript = $session->finalTranscript;
                if ($transcript === null || $transcript->status !== TranscriptStatus::Completed) {
                    continue;
                }
                $file = $this->exporter->export($transcript->setRelation('captureSession', $session), $format, ['view' => $view]);
                $zip->addFromString(self::uniqueName($file['filename'], $names), $file['body']);
            }
            $count = count($names);
            $zip->close();
            if ($count === 0) {
                throw new ApiError(409, 'nothing_to_export', 'None of these recordings has a finished transcript.');
            }
            app(AuditLog::class)->record('sessions_exported', null, null, "{$count} transcripts exported as {$format}", ['format' => $format, 'count' => $count]);

            return ['body' => (string) file_get_contents($path), 'filename' => Brand::name().' transcripts '.now($this->settings->displayTimezone())->format('Y-m-d').'.zip', 'count' => $count];
        } finally {
            @unlink($path);
        }
    }

    /** @param list<string> $names */
    private static function uniqueName(string $filename, array &$names): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = $filename;
        for ($i = 2; in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true); $i++) {
            $name = "{$base} ({$i}).{$extension}";
        }
        $names[] = $name;

        return $name;
    }
}

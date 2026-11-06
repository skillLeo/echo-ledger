<?php

declare(strict_types=1);

namespace App\Services\Connector;

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Http\Controllers\Api\ScreenController;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientContact;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\Screen;
use App\Models\Transcript;
use App\Models\User;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\LibrarySearch;
use App\Services\Projects\ProjectTree;
use App\Services\Screens\MeetingPack;
use App\Services\Screens\ScreenStore;
use App\Services\SettingsRepository;
use App\Services\Sharing\Teammates;
use App\Services\TranscriptEditor;
use App\Services\TranscriptExporter;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\PlanLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the connector's tools read (since v1.5, docs/API.md "Claude and ChatGPT connector"), as plain text for an AI to
 * read. It sees what the person can open in the apps, through the same queries: their own recordings and projects in
 * their current workspace (as the library and search ask them) and what was sent to them (App\Services\Sharing\Teammates).
 * It only reads, and it never makes a view or anything else that costs money.
 */
final class ConnectorLibrary
{
    /** How many memory lines and call notes a brief carries. */
    private const BRIEF_MEMORY = 100;

    private const BRIEF_NOTES = 5;

    /** The longest pasted instructions shown, and the most projects one answer lists. */
    private const INSTRUCTIONS_CHARS = 20000;

    private const MAX_PROJECTS = 300;

    /** What each view is called in an answer. */
    private const VIEW_NAMES = ['original' => 'Original', 'roman' => 'Roman Urdu', 'english' => 'English', 'urdu' => 'Urdu'];

    public function __construct(
        private readonly LibrarySearch $search,
        private readonly ProjectTree $projects,
        private readonly Teammates $teammates,
        private readonly TranscriptExporter $exporter,
        private readonly TranscriptEditor $editor,
        private readonly TranscriptViews $views,
        private readonly SettingsRepository $settings,
        private readonly ClientWorkspace $workspace,
        private readonly PlanLimits $plans,
    ) {}

    /** list_projects: every project with its path, or with `parent_id` the subfolders directly in that one. */
    public function projectList(User $person, int $workspaceId, ?string $parentId): string
    {
        if ($parentId !== null) {
            $this->project($person, $workspaceId, $parentId);
        }
        $all = $this->projects->payloads($workspaceId, $person->id);
        $shown = array_values(array_filter($all, fn (array $p): bool => $parentId === null || $p['parent_id'] === $parentId));
        if ($shown === []) {
            return $parentId === null ? 'You have no projects yet.' : 'This project has no subfolders.';
        }
        $latest = CaptureSession::query()->where('workspace_id', $workspaceId)->where('user_id', $person->id)
            ->whereNotNull('client_id')->where('status', '!=', SessionStatus::Recording->value)
            ->groupBy('client_id')->selectRaw('client_id, MAX(started_at) AS latest')->pluck('latest', 'client_id');

        $lines = [];
        foreach (array_slice($shown, 0, self::MAX_PROJECTS) as $project) {
            $path = implode(' / ', [...array_column($project['path'], 'name'), $project['name']]);
            $last = $latest[$project['id']] ?? null;
            $lines[] = "- {$path} (id {$project['id']}): ".self::count($project['session_count'], 'recording')
                .($project['children_count'] > 0 ? ', '.$project['nested_session_count'].' with its subfolders' : '')
                .($last !== null ? ', last recording '.$this->date(Carbon::parse((string) $last, 'UTC')) : '');
        }
        if (count($shown) > self::MAX_PROJECTS) {
            $lines[] = 'Only the first '.self::MAX_PROJECTS.' of '.count($shown).' are listed.';
        }

        return implode("\n", $lines);
    }

    /** search: the person's own recordings (within a project and its subfolders when one is named), then, without a project, what was sent to them. */
    public function search(User $person, int $workspaceId, string $query, ?string $projectId, int $limit): string
    {
        if (LibrarySearch::terms($query) === []) {
            return 'Nothing to look for in that query.';
        }
        $filters = $projectId !== null ? ['client_ids' => $this->projects->withDescendants($this->project($person, $workspaceId, $projectId))] : [];
        $own = $this->search->search($workspaceId, $query, $filters, $limit);
        $paths = $this->paths($person, $workspaceId);
        $blocks = [];
        foreach ($own['items'] as $session) {
            $blocks[] = $this->hitBlock($session, $own['hits'][$session->id]['hits'], $this->projectLabel($session, $paths));
        }
        $more = $own['more'];
        if ($projectId === null) {
            // With the limit reached, one more is asked for only to say that more match.
            $room = $limit - count($blocks);
            $shared = $this->search->searchIn($this->teammates->sharedSessions($person)->with('transcripts'), $query, max(1, $room));
            foreach ($shared['items']->take($room) as $session) {
                $blocks[] = $this->hitBlock(Teammates::readOnly($session), $shared['hits'][$session->id]['hits'], 'shared with you');
            }
            $more = $more || $shared['more'] || $shared['items']->count() > $room;
        }
        if ($blocks === []) {
            return 'No recording matches that.';
        }

        return implode("\n\n", $blocks).($more ? "\n\nMore recordings match. Ask for a higher limit or narrow the search." : '');
    }

    /** get_recording: the facts, the transcript with the screens at their moments, then each screen's text and picture link. */
    public function recording(User $person, int $workspaceId, string $id, ?string $view, bool $withScreens): string
    {
        $session = CaptureSession::query()->where('workspace_id', $workspaceId)->where('user_id', $person->id)->find($id);
        $shared = false;
        if (! $session instanceof CaptureSession) {
            $session = $this->teammates->readable($person, $id);
            $shared = true;
        }
        if (! $session instanceof CaptureSession) {
            throw new ToolError('No recording with that id.');
        }
        $transcript = $session->transcripts()->where('kind', TranscriptKind::Final->value)->where('status', TranscriptStatus::Completed->value)->first();
        $screens = $withScreens ? ScreenStore::ordered($session->id)->get()->values() : new Collection;
        $facts = [
            'Title' => $session->displayTitle(),
            'Recording id' => $session->id,
            'Date' => $this->date($session->started_at),
            'Length' => $session->duration_ms ? TranscriptExporter::clock($session->duration_ms) : null,
            'People' => null,
            'Project' => $shared ? 'Shared with you (read-only)' : ($this->paths($person, $workspaceId)[(string) $session->client_id] ?? 'In no project'),
            'App' => $session->source_app->getLabel(),
        ];
        if (! $transcript instanceof Transcript) {
            return self::factLines($facts)."\n\nThe transcript of this recording is not ready yet.";
        }

        $transcript->setRelation('captureSession', $session);
        [$shown, $note] = $this->chooseView($transcript, $view);
        $lines = $this->exporter->lines($transcript, $session, $shown);
        $facts['People'] = self::people($lines, $session);
        $facts['View'] = self::VIEW_NAMES[$shown ?? 'original'];

        $out = [self::factLines($facts)];
        if ($note !== null) {
            $out[] = $note;
        }
        $body = [];
        foreach (MeetingPack::timeline($lines, $screens) as $item) {
            if ($item[0] === 'line') {
                $line = $item[1];
                $body[] = '['.TranscriptExporter::clock((int) $line['start_ms']).'] '.($line['speaker'] !== '' ? $line['speaker'].': ' : '').$line['text'];
            } else {
                $body[] = self::screenLine($item[1], $item[2]);
            }
        }
        $out[] = "Transcript\n".($body === [] ? '(no words were recognised)' : implode("\n", $body));
        if ($screens->isNotEmpty()) {
            $out[] = self::screensSection($screens);
        }

        return implode("\n\n", $out);
    }

    /** get_project_brief: the person's own project only; a project sent to them shows through its recordings. */
    public function brief(User $person, int $workspaceId, string $projectId): string
    {
        $project = $this->project($person, $workspaceId, $projectId);
        $sections = ClientBrief::query()->where('client_id', $project->id)->first()?->toApi()['sections'] ?? ClientBrief::emptySections();
        $out = ['Project: '.($this->paths($person, $workspaceId)[$project->id] ?? $project->name)." (id {$project->id})"];

        $brief = $sections['goal'] !== '' ? ['Goal: '.$sections['goal']] : [];
        foreach (array_slice(ClientBrief::SECTIONS, 1) as $key) {
            if ($sections[$key] !== []) {
                $brief[] = ucfirst($key).":\n- ".implode("\n- ", $sections[$key]);
            }
        }
        $out[] = "Brief\n".($brief === [] ? '(empty)' : implode("\n", $brief));

        $files = ClientFile::query()->where('client_id', $project->id)->latest()->get();
        $instructions = $files->filter(fn (ClientFile $f): bool => $f->path === null && $f->text !== null);
        if (filled($project->notes) || $instructions->isNotEmpty()) {
            $notes = filled($project->notes) ? [(string) $project->notes] : [];
            foreach ($instructions as $file) {
                $notes[] = $file->name.":\n".mb_strimwidth((string) $file->text, 0, self::INSTRUCTIONS_CHARS, '…');
            }
            $out[] = "Notes and instructions\n".implode("\n\n", $notes);
        }

        $contacts = $project->contacts()->orderBy('name')->get();
        if ($contacts->isNotEmpty()) {
            $out[] = "Contacts\n".$contacts->map(fn (ClientContact $c): string => '- '.implode(' · ', array_filter([$c->name, $c->phone, $c->email])))->implode("\n");
        }

        $names = $files->reject(fn (ClientFile $f): bool => $f->path === null);
        if ($names->isNotEmpty()) {
            $out[] = "Files\n".$names->map(fn (ClientFile $f): string => '- '.$f->name)->implode("\n");
        }

        if (! $this->plans->allows($workspaceId, 'ai_notes')) {
            $out[] = 'Memory and call notes are not in your plan.';

            return implode("\n\n", $out);
        }

        $memory = ClientMemoryItem::query()->ofProjectMaker($project)->orderByDesc('pinned')->orderByDesc('happened_on')->orderByDesc('created_at')->limit(self::BRIEF_MEMORY)->get();
        if ($memory->isNotEmpty()) {
            $out[] = "Memory\n".$memory->map(fn (ClientMemoryItem $m): string => '- '.ucfirst($m->kind).($m->happened_on ? ' ('.$m->happened_on->toDateString().')' : '').': '.$m->text)->implode("\n");
        }
        $notes = CallNote::query()->ofProjectMaker($project)->where('status', 'ready')->with('captureSession')->latest()->limit(self::BRIEF_NOTES)->get();
        if ($notes->isNotEmpty()) {
            $out[] = "Latest call notes\n".$notes->map(fn (CallNote $note): string => $this->workspace->noteText($note))->implode("\n\n");
        }

        return implode("\n\n", $out);
    }

    /** list_recent: the latest recordings, and without a project what was sent to the person among them. */
    public function recent(User $person, int $workspaceId, int $count, ?string $projectId): string
    {
        $own = CaptureSession::query()->where('workspace_id', $workspaceId)->where('user_id', $person->id)->where('status', '!=', SessionStatus::Recording->value);
        if ($projectId !== null) {
            $own->whereIn('client_id', $this->projects->withDescendants($this->project($person, $workspaceId, $projectId)));
        }
        $latest = $own->orderByDesc('started_at')->orderByDesc('id')->limit($count)->get()->map(fn (CaptureSession $s): array => [$s, false]);
        if ($projectId === null) {
            $sent = $this->teammates->sharedSessions($person)->orderByDesc('started_at')->orderByDesc('id')->limit($count)->get();
            $latest = $latest->concat($sent->map(fn (CaptureSession $s): array => [$s, true]))
                ->sortByDesc(fn (array $row): int => $row[0]->started_at->getTimestamp())->unique(fn (array $row): string => $row[0]->id)->take($count)->values();
        }
        if ($latest->isEmpty()) {
            return 'No recordings yet.';
        }
        $paths = $this->paths($person, $workspaceId);

        return $latest->map(function (array $row) use ($paths): string {
            [$session, $shared] = $row;

            return '- '.$session->displayTitle()." (id {$session->id}): ".$this->date($session->started_at)
                .($session->duration_ms ? ', '.TranscriptExporter::clock($session->duration_ms) : '')
                .', '.($shared ? 'shared with you' : $this->projectLabel($session, $paths))
                .', '.$session->source_app->getLabel();
        })->implode("\n");
    }

    /** The person's own project in this workspace, the same lookup as the apps' project routes. */
    private function project(User $person, int $workspaceId, string $id): Client
    {
        $project = Client::query()->where('workspace_id', $workspaceId)->where('user_id', $person->id)->find($id);
        if (! $project instanceof Client) {
            throw new ToolError('No project with that id.');
        }

        return $project;
    }

    /**
     * Each of the person's projects by id as "Acme / Website".
     *
     * @return array<string, string>
     */
    private function paths(User $person, int $workspaceId): array
    {
        $paths = [];
        foreach ($this->projects->payloads($workspaceId, $person->id) as $project) {
            $paths[$project['id']] = implode(' / ', [...array_column($project['path'], 'name'), $project['name']]);
        }

        return $paths;
    }

    /** @param array<string, string> $paths */
    private function projectLabel(CaptureSession $session, array $paths): string
    {
        return $paths[(string) $session->client_id] ?? 'in no project';
    }

    /**
     * The view to show, as the meeting pack does: the one asked for, else the one the console opens this transcript in,
     * and the original words (with a note, when one was asked for) while a view is not made.
     *
     * @return array{0: string|null, 1: string|null} the view, null for the original words, and the note
     */
    private function chooseView(Transcript $transcript, ?string $asked): array
    {
        $wanted = $asked ?? TranscriptViews::defaultView(TranscriptViews::available($transcript), TranscriptViews::sourceLanguage($transcript));
        if ($wanted === 'original') {
            return [null, null];
        }
        if ($this->views->textsFor($transcript, $wanted) !== null) {
            return [$wanted, null];
        }

        return [null, $asked !== null ? 'The '.self::VIEW_NAMES[$wanted].' view of this recording is not made yet, so these are the original words.' : null];
    }

    /**
     * One search result: the recording, then its matching lines; a title-only match has none.
     *
     * @param  list<array<string, mixed>>  $hits
     */
    private function hitBlock(CaptureSession $session, array $hits, string $project): string
    {
        $block = ['- '.$session->displayTitle()." (id {$session->id}): ".$this->date($session->started_at).', '.$project];
        $names = $this->editor->speakerNames($session);
        foreach ($hits as $hit) {
            $at = TranscriptExporter::clock((int) $hit['start_ms']);
            $speaker = (string) ($names[$hit['speaker']] ?? $hit['speaker']);
            $block[] = ($hit['kind'] ?? null) === 'screen'
                ? "  [{$hit['speaker']} at {$at}] {$hit['snippet']}"
                : "  [{$at}] ".($speaker !== '' ? $speaker.': ' : '').$hit['snippet'];
        }
        if ($hits === []) {
            $block[] = '  (the title matches)';
        }

        return implode("\n", $block);
    }

    /** `[Screen 7 at 14:32: The client's invoice page]`, as Copy for AI writes it. */
    private static function screenLine(Screen $screen, int $number): string
    {
        return '['.MeetingPack::label($number, $screen->at_ms).($screen->description !== null ? ': '.$screen->description : '').']';
    }

    /**
     * Each screen's text and its signed picture link (30 minutes, ScreenController::LINK_MINUTES).
     *
     * @param  Collection<int, Screen>  $screens  in time order
     */
    private static function screensSection(Collection $screens): string
    {
        $out = ['Screens (each picture link works for '.ScreenController::LINK_MINUTES.' minutes)'];
        foreach ($screens as $i => $screen) {
            $payload = ScreenController::payload($screen, $i + 1);
            $text = trim((string) preg_replace('/[ \t]+/u', ' ', (string) $screen->ocr_text));
            $out[] = MeetingPack::label($i + 1, $screen->at_ms)."\n"
                .($text !== '' ? "Text: {$text}\n" : '')
                .'Picture: '.$payload['image_url'];
        }

        return implode("\n\n", $out);
    }

    /**
     * The speakers in the transcript and who the recording was with.
     *
     * @param  list<array{speaker: string}>  $lines
     */
    private static function people(array $lines, CaptureSession $session): ?string
    {
        $people = array_values(array_unique(array_filter(array_map(fn (array $l): string => trim($l['speaker']), $lines))));
        if ($session->caller_name !== null && $session->caller_name !== '' && ! in_array($session->caller_name, $people, true)) {
            $people[] = $session->caller_name;
        }

        return $people === [] ? null : implode(', ', $people);
    }

    /** @param array<string, string|null> $facts */
    private static function factLines(array $facts): string
    {
        $lines = [];
        foreach ($facts as $name => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = "{$name}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    private static function count(int $n, string $what): string
    {
        return $n === 1 ? "1 {$what}" : "{$n} {$what}s";
    }

    private function date(Carbon $at): string
    {
        return $at->copy()->setTimezone($this->settings->displayTimezone())->format('j M Y, H:i T');
    }
}

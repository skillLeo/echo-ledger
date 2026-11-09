<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Admin\Pages\ApiCredentials;
use App\Filament\Admin\Pages\Dashboard as AdminDashboard;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\GetTheApps;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\Search;
use App\Filament\Pages\Upload;
use App\Filament\Pages\WorkspaceSettings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LibrarySearch;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use App\Services\TranscriptExporter;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The console's command palette (Ctrl / Cmd + K, v1.1): pages and actions to go to, recordings whose title or
 * transcript matches (opening at the moment that matched), and projects. Since v1.6 (P4.3) it is the top bar's
 * "Search recordings, transcripts, pages… or type a command": the commands (Record now, Upload, New project,
 * Invite, Request link) and every page of Find a page, each only for whoever may use it.
 */
final class CommandPalette implements GlobalSearchProvider
{
    public function getResults(string $query): GlobalSearchResults
    {
        $query = trim($query);
        $results = GlobalSearchResults::make();
        if ($query === '') {
            return $results;
        }
        $actions = array_filter(self::actions(), fn (array $c): bool => self::matches($query, $c[0].' '.$c[3]));
        if ($actions !== []) {
            $results->category('Commands', array_map(fn (array $c): GlobalSearchResult => new GlobalSearchResult($c[0], $c[1], ['' => $c[2]]), array_values($actions)));
        }
        $commands = array_filter($this->commands(), fn (array $c): bool => self::matches($query, $c[0].' '.$c[2]));
        if ($commands !== []) {
            $results->category('Go to', array_map(fn (array $c): GlobalSearchResult => new GlobalSearchResult($c[0], $c[1], ['' => $c[2]]), array_values($commands)));
        }
        if (mb_strlen($query) >= 2 && LibrarySearch::terms($query) !== []) {
            $recordings = $this->recordings($query);
            if ($recordings !== []) {
                $results->category('Recordings', $recordings);
            }
        }
        $clients = Client::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId())->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%')
            ->orderBy('name')->limit(5)->get()
            ->map(fn (Client $c): GlobalSearchResult => new GlobalSearchResult($c->name, ClientResource::getUrl('edit', ['record' => $c])))->all();
        if ($clients !== []) {
            $results->category('Projects', $clients);
        }

        return $results;
    }

    /** @return list<array{0: string, 1: string, 2: string}> label, url, words it answers to */
    private function commands(): array
    {
        // Since v1.5 only the pages the person may open (the small console has fewer).
        $list = [
            ['Dashboard', Dashboard::getUrl(), 'home overview usage chart', true],
            ['Search recordings', Search::getUrl(), 'find transcript text words', Search::canAccess()],
            ['Library', CaptureSessionResource::getUrl('index'), 'recordings calls library voice notes upload', CaptureSessionResource::canAccess()],
            ['Projects', ClientResource::getUrl('index'), 'clients customers folders keyterms vocabulary corrections', ClientResource::canAccess()],
            ['Devices', DeviceResource::getUrl('index'), 'phones computers sign in out pair unpair', DeviceResource::canAccess()],
            ['Settings', Settings::getUrl(panel: 'admin'), 'retention language vocabulary bookmarks limit engines', Settings::canAccess()],
            ['API credentials', ApiCredentials::getUrl(panel: 'admin'), 'keys assemblyai elevenlabs azure whisper', ApiCredentials::canAccess()],
            ['Admin panel', AdminDashboard::getUrl(panel: 'admin'), 'admin staff customers plans upgrade requests', StaffAccess::isStaff()],
        ];

        $commands = array_values(array_map(fn (array $c): array => [$c[0], $c[1], $c[2]], array_filter($list, fn (array $c): bool => $c[1] !== '' && $c[3])));
        // Since v1.6 every page of the sidebar's Find a page too (Account's tabs, the Library's places, the projects
        // are under Projects below), once each; Devices only where the console has its page.
        $urls = array_column($commands, 1);
        foreach (Shell::pages() as $page) {
            if (in_array($page['url'], $urls, true) || str_starts_with($page['id'], 'project-') || ($page['id'] === 'devices' && ! DeviceResource::canAccess())) {
                continue;
            }
            $commands[] = [$page['label'], $page['url'], trim($page['group'].' '.$page['words'])];
            $urls[] = $page['url'];
        }

        return $commands;
    }

    /**
     * What the palette does rather than opens a page for: label, where it goes, a line about it, the words it answers to.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function actions(): array
    {
        $readOnly = ConsoleAccess::readOnly();
        $user = auth()->user();
        $hasDevice = $user instanceof User && Device::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', $user->id)->whereNull('signed_out_at')->exists();
        $list = [
            // The console does not record: the phone and the computer do.
            $hasDevice
                ? ['Record now', Upload::getUrl(['record' => 1], panel: 'app'), 'On your phone or computer; here: a voice note from this phone, or a file', 'start voice note new', ! $readOnly && Upload::canAccess()]
                : ['Record now', GetTheApps::getUrl(panel: 'app'), 'Get the phone or computer app to record', 'start voice note new download install', ! $readOnly],
            ['Upload', Upload::getUrl(panel: 'app'), 'Voice notes, audio or video files', 'import files add', ! $readOnly && Upload::canAccess()],
            ['New project', ClientResource::getUrl('create', panel: 'app'), 'A folder for one customer\'s calls and files', 'create folder customer', ! $readOnly && ClientResource::canAccess()],
            ['Invite', WorkspaceSettings::getUrl(['action' => 'addMember'], panel: 'app'), 'Ask someone to join this workspace', 'member team people add', WorkspaceSettings::canAccess() && self::mayInvite($user instanceof User ? $user : null)],
            ['New request link', RequestLinksPage::getUrl(['action' => 'create'], panel: 'app'), 'A link people use to send you files', 'ask upload files', ! $readOnly && RequestLinksPage::canAccess()],
        ];

        return array_values(array_map(fn (array $c): array => [$c[0], $c[1], $c[2], $c[3]], array_filter($list, fn (array $c): bool => $c[4])));
    }

    /** @return list<GlobalSearchResult> */
    private function recordings(string $query): array
    {
        try {
            $found = app(LibrarySearch::class)->search((int) CurrentWorkspace::id(), $query, [], 6);
        } catch (Throwable) {
            return [];
        }
        $tz = app(SettingsRepository::class)->displayTimezone();
        $out = [];
        foreach ($found['items'] as $session) {
            $hit = $found['hits'][$session->id]['hits'][0] ?? null;
            $url = CaptureSessionResource::getUrl('view', ['record' => $session]).($hit !== null ? '?t='.$hit['start_ms'] : '');
            $details = ['When' => $session->started_at->copy()->setTimezone($tz)->format('M j, Y · '.Shell::timeFormat())];
            if ($hit !== null) {
                $details[TranscriptExporter::clock($hit['start_ms'])] = $hit['snippet'];
            }
            $out[] = new GlobalSearchResult($session->displayTitle(), $url, $details);
        }

        return $out;
    }

    /** Whether the person may invite to their current workspace (WorkspacePolicy's invite) and the plan has teams. */
    private static function mayInvite(?User $user): bool
    {
        $workspace = Workspace::query()->find(CurrentWorkspace::id());

        return $user !== null && $workspace instanceof Workspace && PlanLock::allows('team') && Gate::forUser($user)->allows('invite', [$workspace]);
    }

    private static function matches(string $query, string $haystack): bool
    {
        $haystack = mb_strtolower($haystack);
        foreach (preg_split('/\s+/u', mb_strtolower($query)) ?: [] as $word) {
            if ($word !== '' && ! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}

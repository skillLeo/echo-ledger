<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Pages\ApiCredentials;
use App\Filament\Pages\Search;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Client;
use App\Services\LibrarySearch;
use App\Services\SettingsRepository;
use App\Services\TranscriptExporter;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\Pages\Dashboard;
use Throwable;

/**
 * The console's command palette (Ctrl / Cmd + K, v1.1): pages and actions to go to, recordings whose title or
 * transcript matches (opening at the moment that matched), and projects.
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
            ['Sessions', CaptureSessionResource::getUrl('index'), 'recordings calls library voice notes upload', CaptureSessionResource::canAccess()],
            ['Projects', ClientResource::getUrl('index'), 'clients customers folders keyterms vocabulary corrections', ClientResource::canAccess()],
            ['Devices', DeviceResource::getUrl('index'), 'phones computers pair unpair', DeviceResource::canAccess()],
            ['Settings', Settings::getUrl(), 'retention language vocabulary bookmarks limit engines', Settings::canAccess()],
            ['API credentials', ApiCredentials::getUrl(), 'keys assemblyai elevenlabs azure whisper', ApiCredentials::canAccess()],
        ];

        return array_values(array_map(fn (array $c): array => [$c[0], $c[1], $c[2]], array_filter($list, fn (array $c): bool => $c[1] !== '' && $c[3])));
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
            $details = ['When' => $session->started_at->copy()->setTimezone($tz)->format('M j, Y · g:i A')];
            if ($hit !== null) {
                $details[TranscriptExporter::clock($hit['start_ms'])] = $hit['snippet'];
            }
            $out[] = new GlobalSearchResult($session->displayTitle(), $url, $details);
        }

        return $out;
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

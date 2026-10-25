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
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\Pages\Dashboard;
use Throwable;

/**
 * The console's command palette (Ctrl / Cmd + K, v1.1): pages and actions to go to, recordings whose title or
 * transcript matches (opening at the moment that matched), and clients.
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
        $clients = Client::query()->where('user_id', auth()->id())->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%')
            ->orderBy('name')->limit(5)->get()
            ->map(fn (Client $c): GlobalSearchResult => new GlobalSearchResult($c->name, ClientResource::getUrl('edit', ['record' => $c])))->all();
        if ($clients !== []) {
            $results->category('Clients', $clients);
        }

        return $results;
    }

    /** @return list<array{0: string, 1: string, 2: string}> label, url, words it answers to */
    private function commands(): array
    {
        $list = [
            ['Dashboard', Dashboard::getUrl(), 'home overview usage chart'],
            ['Search recordings', Search::getUrl(), 'find transcript text words'],
            ['Sessions', CaptureSessionResource::getUrl('index'), 'recordings calls library voice notes upload'],
            ['Clients', ClientResource::getUrl('index'), 'customers keyterms vocabulary corrections'],
            ['Devices', DeviceResource::getUrl('index'), 'phones computers pair unpair'],
            ['Settings', Settings::getUrl(), 'retention language vocabulary bookmarks limit engines'],
            ['API credentials', ApiCredentials::getUrl(), 'keys assemblyai elevenlabs azure whisper'],
        ];

        return array_values(array_filter($list, fn (array $c): bool => $c[1] !== ''));
    }

    /** @return list<GlobalSearchResult> */
    private function recordings(string $query): array
    {
        try {
            $found = app(LibrarySearch::class)->search((int) auth()->id(), $query, [], 6);
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

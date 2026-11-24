<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\InterfaceFlags;
use App\Services\Library\LibraryFilters;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Contract 2.0.0-q (docs/API.md "The Library's kinds", I-07): every recording has exactly one kind (call, meeting,
| voice_note or other, from its Source), imported files and every other Source are other, and the Library's kind tabs
| always add up to All: on the API (`GET /api/library/facets`'s `kinds`, each item's `facets.kind`) and on the console.
*/

uses(ApiClient::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'UTC'));
    Fx::fakeRecordingsDisk();
});

/** One recording of [$owner]'s per [Source, origin], started a day apart (the newest first). */
function kindsAccount(User $owner, array $recordings): void
{
    $device = Fx::device($owner);
    foreach (array_values($recordings) as $i => [$source, $origin]) {
        Fx::session($owner, $device, ['title' => "Recording {$i}", 'source_app' => $source, 'origin' => $origin, 'status' => SessionStatus::Completed,
            'started_at' => now()->subDays($i + 1), 'ended_at' => now()->subDays($i + 1)->addMinutes(5)]);
    }
}

/** Five seeded accounts: calls only, meetings and voice notes, imported and requested files, every Source, none. */
function kindsAccounts(): array
{
    $accounts = [];
    $mixes = [
        'calls only' => [['whatsapp', null], ['whatsapp_web', null], ['phone_call', null], ['telegram', null]],
        'meetings and voice notes' => [['zoom', null], ['google_meet', null], ['microsoft_teams', null], ['voice_note', null], ['voice_note', null]],
        'imported and requested files' => [['voice_note', CaptureSession::ORIGIN_IMPORTED], ['whatsapp', CaptureSession::ORIGIN_IMPORTED],
            ['other', CaptureSession::ORIGIN_REQUESTED], ['zoom', CaptureSession::ORIGIN_SAVED], ['phone_call', null]],
        'every Source' => array_map(fn (SourceApp $app): array => [$app->value, null], SourceApp::cases()),
        'nothing yet' => [],
    ];
    foreach ($mixes as $name => $recordings) {
        $owner = Fx::user();
        kindsAccount($owner, $recordings);
        $accounts[$name] = [$owner, Fx::token(Fx::device($owner))];
    }

    return $accounts;
}

it('gives every recording exactly one kind, and the kinds add up to every recording, for five seeded accounts', function () {
    foreach (kindsAccounts() as $name => [$owner, $token]) {
        $answer = $this->api($token)->getJson('/api/library/facets?tz=Asia/Karachi')->assertOk()->json();
        expect(array_keys($answer['kinds']))->toBe(['call', 'meeting', 'voice_note', 'other'], $name)
            ->and(array_sum($answer['kinds']))->toBe($answer['total'], "{$name}: the kinds add up to All")
            ->and($answer['total'])->toBe($answer['library']['all'], $name);
        // Each library item carries its one kind, the one its Source names, and they count the same.
        $items = $this->api($token)->getJson('/api/library?limit=100')->assertOk()->json('data');
        expect(count($items))->toBe($answer['total'], $name);
        $byKind = array_fill_keys(LibraryFilters::KIND_NAMES, 0);
        foreach ($items as $item) {
            expect($item['facets']['kind'])->toBe(LibraryFilters::kind($item['facets']['source']), "{$name}: {$item['title']}");
            $byKind[$item['facets']['kind']]++;
        }
        expect($byKind)->toBe($answer['kinds'], $name);
    }
});

it('puts imported and requested files and the Other Source under other, and a teammate\'s saved copy under its app', function () {
    $owner = Fx::user();
    kindsAccount($owner, [['voice_note', CaptureSession::ORIGIN_IMPORTED], ['whatsapp', CaptureSession::ORIGIN_IMPORTED], ['other', CaptureSession::ORIGIN_REQUESTED],
        ['other', null], ['zoom', CaptureSession::ORIGIN_SAVED], ['whatsapp_web', null]]);
    $answer = $this->api(Fx::token(Fx::device($owner)))->getJson('/api/library/facets')->assertOk()->json();

    expect($answer['kinds'])->toBe(['call' => 1, 'meeting' => 1, 'voice_note' => 0, 'other' => 4])->and($answer['total'])->toBe(6);
});

it('keeps the kinds adding up to the total with filters and a search, each kind counted within them', function () {
    [$owner, $token] = kindsAccounts()['imported and requested files'];
    foreach ([
        ['filters' => json_encode(['date' => ['op' => 'is', 'values' => ['last_7_days']]])],
        ['filters' => json_encode(['source' => ['op' => 'is_not', 'values' => ['imported']]])],
        ['filters' => json_encode(['source' => ['op' => 'is', 'values' => ['imported']]])],
        ['q' => 'Recording 1'],
        ['q' => 'nothing like this'],
    ] as $query) {
        $answer = $this->api($token)->getJson('/api/library/facets?'.http_build_query($query))->assertOk()->json();
        expect(array_sum($answer['kinds']))->toBe($answer['total'], json_encode($query));
    }
    $imported = $this->api($token)->getJson('/api/library/facets?'.http_build_query(['filters' => json_encode(['source' => ['op' => 'is', 'values' => ['imported']]])]))->json();
    // The two imported files and the requested one are all Source imported, so all other.
    expect($imported['kinds'])->toBe(['call' => 0, 'meeting' => 0, 'voice_note' => 0, 'other' => 3]);
});

it('counts any Source no kind names as other, so a Source added later still adds up', function () {
    foreach (SourceApp::cases() as $app) {
        $source = LibraryFilters::source($app->value, null);
        expect(LibraryFilters::kind($source))->toBeIn(LibraryFilters::KIND_NAMES, $app->value);
    }
    // A Source the server does not know reads as other, and other narrows to every Source no named kind lists.
    expect(LibraryFilters::source('zalo', null))->toBe('other')
        ->and(LibraryFilters::kind('zalo'))->toBe('other')
        ->and(LibraryFilters::kind('imported'))->toBe('other')
        ->and(LibraryFilters::kindSources('other'))->toBe(['imported', 'other'])
        ->and(LibraryFilters::kindSources('call'))->toBe(LibraryFilters::KIND_SOURCES['call'])
        ->and(LibraryFilters::kindSources('calls'))->toBeNull();
    // Every Source the filters offer is in exactly one kind.
    foreach (LibraryFilters::SOURCES as $source) {
        $in = array_filter(LibraryFilters::KIND_NAMES, fn (string $kind): bool => in_array($source, LibraryFilters::kindSources($kind) ?? [], true));
        expect(count($in))->toBe(1, $source);
    }
});

it('shows the console\'s Other tab only while it has something, and the kind tabs add up to All', function () {
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    foreach (kindsAccounts() as $name => [$owner]) {
        $this->actingAs($owner->fresh(), 'web');
        $tabs = collect(Livewire::withQueryParams([])->test(ListCaptureSessions::class)->instance()->libraryTabs())->pluck('count', 'key');
        expect($tabs['all'])->toBe($tabs->except('all')->sum(), "{$name}: the tabs add up to All")
            ->and($tabs->has('other'))->toBe($name === 'imported and requested files' || $name === 'every Source', "{$name}: Other only while it has something");
    }
    Livewire::withQueryParams([]);
});

it('names the Other tab in English and in Urdu, with its count, and keeps it in the address', function () {
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    [$owner] = kindsAccounts()['imported and requested files'];
    $tab = fn (string $html): string => (string) preg_replace('~^.*?(<button[^>]*data-library-tab="other">.*?</button>).*$~s', '$1', $html);
    foreach (['en' => 'Other', 'ur' => 'دیگر'] as $language => $word) {
        $owner->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
        $html = (string) $this->actingAs($owner->fresh(), 'web')->get('/app/sessions?kind=other')->assertOk()->getContent();
        expect($tab($html))->toContain('<span>'.$word.'</span>')->toMatch('~<span class="v2-tab-count">\D*3\D*</span>~')
            ->toMatch('~class="v2-tab is-on"\s+aria-current="page"~');
    }
});

<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The classic console stays exactly as it is while the v2.0 flags are off (ui_v2.shell, ui_v2.library): Home, the
 * Library and a recording page, for fixed people and recordings at a fixed time, are compared with the HTML saved in
 * tests/Feature/V2/golden/ before the v2.0 console was built. Only what changes on every request is normalised (the
 * CSRF token, Livewire's snapshots and ids, record ids, signed links). A change to the classic console on purpose:
 * UPDATE_GOLDEN=1 php artisan test --filter=ClassicGolden, and say why in the commit.
 */

/** The page's HTML with what changes on every request replaced by a placeholder. */
function normaliseClassic(string $html, string $csrf = ''): string
{
    if ($csrf !== '') {
        $html = str_replace($csrf, 'CSRF', $html);
    }
    // The self-hosted Inter faces are inlined only where `filament:assets` has published them (not in every checkout).
    $html = (string) preg_replace("/<style>(@font-face\\{font-family:'Inter';[^}]*\\})+<\\/style>/", '', $html);
    // Lazy components carry their (random) id and a checksum, base64-encoded.
    $html = (string) preg_replace("/__lazyLoad\\(&#039;[A-Za-z0-9+\/=]+&#039;\\)/", '__lazyLoad(LAZY)', $html);
    // Livewire's component ids (20 random letters and digits), wherever a page repeats them.
    preg_match_all('/wire:id="([A-Za-z0-9]{20})"/', $html, $ids);
    $html = str_replace($ids[1], 'WIREID', $html);
    $html = preg_replace('/(name="csrf-token" content=")[^"]+"/', '$1CSRF"', $html);
    $html = preg_replace('/(name="_token"(?: [a-z-]+="[^"]*")* value=")[^"]+"/', '$1CSRF"', (string) $html);
    $html = preg_replace('/(data-csrf=")[^"]+"/', '$1CSRF"', (string) $html);
    $html = preg_replace('/("csrf(?:Token)?"\s*:\s*")[^"]+"/', '$1CSRF"', (string) $html);
    $html = preg_replace('/wire:snapshot="[^"]*"/', 'wire:snapshot="SNAPSHOT"', (string) $html);
    $html = preg_replace('/wire:id="[^"]*"/', 'wire:id="WIREID"', (string) $html);
    // Livewire's keys hold counters, random letters and hashes of record ids.
    $html = preg_replace('/wire:key="[^"]*"/', 'wire:key="KEY"', (string) $html);
    $html = preg_replace("/(livewireId: ')[A-Za-z0-9]+'/", "$1WIREID'", (string) $html);
    $html = preg_replace('/0[0-7][0-9A-HJKMNP-TV-Z]{24}(?![0-9A-Za-z])/i', 'ULID', (string) $html);
    $html = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', 'UUID', (string) $html);
    $html = preg_replace('/(signature(?:=|%3D))[0-9a-f]{64}/i', '$1SIG', (string) $html);
    $html = preg_replace('/(expires(?:=|%3D))\d+/i', '$1EXPIRES', (string) $html);

    return (string) $html;
}

/** Fixed people and recordings at a fixed time: the same HTML on every run. */
function classicConsole(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    $owner = Fx::user(['name' => 'Bilal Qureshi', 'email' => 'bilal@qureshi-textiles.example']);
    $device = Fx::device($owner, ['name' => 'Galaxy S25']);
    $client = Fx::client($owner, 'Qureshi Textiles');
    $recordings = [];
    foreach ([
        ['Payment terms for the March shipment', SourceApp::WhatsApp, 'Today 09:18', 1_104_000, SessionStatus::Completed, $client->id],
        ['Rate for 12 mm rebar, delivery to Gujranwala', SourceApp::PhoneCall, 'Today 08:47', 452_000, SessionStatus::Completed, null],
        ['Umrah group booking for 14 people', SourceApp::WhatsAppBusiness, 'Yesterday 16:30', 348_000, SessionStatus::Completed, $client->id],
    ] as $i => [$title, $app, $when, $ms, $status, $clientId]) {
        $started = Carbon::parse(str_replace(['Today', 'Yesterday'], ['2026-11-17', '2026-11-16'], $when), 'UTC');
        $recordings[] = Fx::session($owner, $device, [
            'client_ref' => sprintf('00000000-0000-4000-8000-%012d', $i + 1),
            'title' => $title, 'source_app' => $app, 'source_device' => SourceDevice::Phone, 'client_id' => $clientId,
            'started_at' => $started, 'ended_at' => $started->copy()->addMilliseconds($ms), 'duration_ms' => $ms, 'status' => $status,
        ]);
    }
    Fx::transcript($recordings[0], 'Assalam o alaikum Bilal sahib, the proforma is ready. Forty percent advance this time.');
    Filament::setCurrentPanel('app');

    return [$owner->fresh(), $recordings];
}

dataset('classic pages', [
    'home' => ['home', fn (array $recordings): string => '/app'],
    'library' => ['library', fn (array $recordings): string => '/app/sessions'],
    'recording' => ['recording', fn (array $recordings): string => '/app/sessions/'.$recordings[0]->id],
]);

// Each page is the first request of its test: Livewire adds its styles to the first page a process serves.
it('renders the classic page exactly as before v2.0 while the v2.0 flags are off', function (string $name, Closure $path) {
    [$owner, $recordings] = classicConsole();
    $started = microtime(true);
    $response = $this->actingAs($owner, 'web')->get($path($recordings))->assertOk();
    $html = normaliseClassic((string) $response->getContent(), (string) session()->token());
    $file = __DIR__."/golden/classic-{$name}.html";
    if (getenv('UPDATE_GOLDEN') === '1') {
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $html);
    }
    expect(file_exists($file))->toBeTrue("no saved copy of {$name}: run with UPDATE_GOLDEN=1 once")
        ->and($html)->toBe(file_get_contents($file), "the classic {$name} page changed");
})->with('classic pages');

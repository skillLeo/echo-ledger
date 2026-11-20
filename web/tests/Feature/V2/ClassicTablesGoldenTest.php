<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Services\ShareLinks;
use App\Services\Sharing\RequestLinks;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The classic console's four wide tables stay exactly as they are while the new frame is off: a recording's Share
 * links, My links, Devices and Request links, each with a row, compared with the HTML saved in tests/Feature/V2/golden/
 * before the new frame fitted them to the page (W-201). A fixed person, device, project and two recordings at a fixed
 * time, with one share link and one request link. A change to classic on purpose: UPDATE_GOLDEN=1 php artisan test --filter=ClassicTablesGolden.
 */

require_once __DIR__.'/Support/golden.php';

/** Fixed people, recordings and links at a fixed time: the same HTML on every run. */
function classicTablesConsole(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    expect(config('app.debug'))->toBeFalse('the saved pages need debug off: run the suite with APP_DEBUG unset');
    $owner = Fx::user(['name' => 'Bilal Qureshi', 'email' => 'bilal@qureshi-textiles.example']);
    $device = Fx::device($owner, ['name' => 'Galaxy S25']);
    $client = Fx::client($owner, 'Qureshi Textiles');
    $recordings = [];
    foreach ([
        ['Payment terms for the March shipment', SourceApp::WhatsApp, '2026-11-17 09:18', 1_104_000, $client->id],
        ['Rate for 12 mm rebar, delivery to Gujranwala', SourceApp::PhoneCall, '2026-11-17 08:47', 452_000, null],
    ] as $i => [$title, $app, $when, $ms, $clientId]) {
        $started = Carbon::parse($when, 'UTC');
        $recordings[] = Fx::session($owner, $device, [
            'client_ref' => sprintf('00000000-0000-4000-8000-%012d', $i + 1),
            'title' => $title, 'source_app' => $app, 'source_device' => SourceDevice::Phone, 'client_id' => $clientId,
            'started_at' => $started, 'ended_at' => $started->copy()->addMilliseconds($ms), 'duration_ms' => $ms, 'status' => SessionStatus::Completed,
        ]);
    }
    Fx::transcript($recordings[0], 'Assalam o alaikum Bilal sahib, the proforma is ready. Forty percent advance this time.');
    app(ShareLinks::class)->create($recordings[0], 7 * 86400, null, false, 'Bilal Qureshi');
    app(RequestLinks::class)->create((int) $owner->current_workspace_id, $owner->id, $client, 'Send me the March samples', now()->addDays(7), 5, 50);
    Filament::setCurrentPanel('app');

    return [$owner->fresh(), $recordings];
}

dataset('classic tables', [
    'recording' => ['recording', fn (array $recordings): string => '/app/sessions/'.$recordings[0]->id],
    'shares' => ['shares', fn (array $recordings): string => '/app/shares'],
    'devices' => ['devices', fn (array $recordings): string => '/app/devices'],
    'request-links' => ['request-links', fn (array $recordings): string => '/app/request-links'],
]);

it('renders the classic tables exactly as before W-201 while the new frame is off', function (string $name, Closure $path) {
    [$owner, $recordings] = classicTablesConsole();

    $response = $this->actingAs($owner, 'web')->get($path($recordings))->assertOk();
    $html = normaliseClassic((string) $response->getContent(), (string) session()->token());
    $file = __DIR__."/golden/classic-table-{$name}.html";
    if (getenv('UPDATE_GOLDEN') === '1') {
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $html);
    }
    expect(file_exists($file))->toBeTrue("no saved copy of {$name}: run with UPDATE_GOLDEN=1 once")
        ->and($html)->toBe(file_get_contents($file), "the classic {$name} table changed");
})->with('classic tables');

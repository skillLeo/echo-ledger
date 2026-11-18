<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Filament\Support\SettingsContext;
use App\Models\User;
use App\Services\Accounts\SettingsRegistry;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The web rows of docs/v2/audit/parity.csv (docs/PLAN_V2_PARITY.md section 1): every row marked "done" whose test is
 * this file names a control that must be on its page in the new design. The checks are tests/Feature/V2/web-parity-checks.json
 * (row id => [page, control]); a control is "element: its text", "~text" (any control containing it) or a prefix ending in ": ".
 */

const WEB_PARITY_CHECKS = __DIR__.'/web-parity-checks.json';

/** The visible controls of a page: links, buttons, menu items, tabs, labels, options, headings, keys, named elements. */
function webParityControls(string $html): array
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xp = new DOMXPath($doc);
    $out = [];
    $q = '//a|//button|//*[@role="menuitem"]|//*[@role="menuitemradio"]|//*[@role="tab"]|//*[@role="switch"]|//label|//option|//th|//h1|//h2|//h3'
        .'|//*[contains(@class,"fi-dropdown-list-item-label")]|//*[contains(@class,"el-set-title")]|//kbd|//*[@aria-label]|//*[@title]';
    foreach ($xp->query($q) as $node) {
        $text = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        if ($text === '' && $node instanceof DOMElement) {
            $text = trim($node->getAttribute('aria-label') ?: $node->getAttribute('title'));
        }
        if ($text !== '' && mb_strlen($text) <= 80) {
            $out[$node->nodeName.': '.$text] = true;
        }
    }

    return array_keys($out);
}

/** @return array{0: User, 1: array<string, string>} the person, and page => URL, with a project and three recordings */
function webParityWorld(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    $owner = Fx::user(['name' => 'Bilal Qureshi', 'email' => 'bilal@qureshi-textiles.example']);
    $device = Fx::device($owner, ['name' => 'Galaxy S25']);
    $client = Fx::client($owner, 'Qureshi Textiles');
    $rec = [];
    foreach ([['Payment terms for the March shipment', SourceApp::WhatsApp, 1_104_000, $client->id], ['Rate for 12 mm rebar', SourceApp::PhoneCall, 452_000, null],
        ['Umrah group booking', SourceApp::Zoom, 348_000, $client->id]] as $i => [$title, $app, $ms, $clientId]) {
        $started = Carbon::parse('2026-11-17 09:00:00')->subHours($i * 20);
        $rec[] = Fx::session($owner, $device, ['client_ref' => 'parity-'.$i, 'title' => $title, 'source_app' => $app, 'source_device' => SourceDevice::Phone,
            'client_id' => $clientId, 'started_at' => $started, 'ended_at' => $started->copy()->addMilliseconds($ms), 'duration_ms' => $ms, 'status' => SessionStatus::Completed]);
    }
    Fx::transcript($rec[0], 'Assalam o alaikum Bilal sahib, the proforma is ready. Forty percent advance this time.');
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    app(InterfaceFlags::class)->set('live_assist', true, 0, [], [], 'everyone');
    $pages = ['home' => '/app', 'library' => '/app/sessions', 'recording' => '/app/sessions/'.$rec[0]->id, 'projects' => '/app/clients',
        'project' => '/app/clients/'.$client->id, 'search' => '/app/search?q=proforma', 'upload' => '/app/upload', 'activity' => '/app/activity',
        'live' => '/app/live', 'shares' => '/app/shares', 'shared-with-me' => '/app/shared-with-me', 'request-links' => '/app/request-links',
        'recently-deleted' => '/app/recently-deleted', 'devices' => '/app/devices', 'usage' => '/app/usage', 'workspace' => '/app/workspace',
        'help' => '/app/help', 'get-the-apps' => '/app/get-the-apps', 'assist-preps' => '/app/live-assist/preps',
        'assist-sessions' => '/app/live-assist/sessions', 'assist-settings' => '/app/live-assist/settings'];
    foreach (array_keys(SettingsRegistry::PAGES) as $sub) {
        $pages['settings-'.$sub] = '/app/settings/'.$sub;
    }

    return [$owner, $pages];
}

function webParityHas(array $controls, string $want): bool
{
    if (str_starts_with($want, '~')) {
        return collect($controls)->contains(fn (string $c): bool => str_contains($c, substr($want, 1)));
    }
    if (str_ends_with($want, ': ')) {
        return collect($controls)->contains(fn (string $c): bool => str_starts_with($c, $want));
    }

    return in_array($want, $controls, true);
}

it('finds every "done" web row\'s control on its page in the new design', function () {
    $checks = json_decode((string) file_get_contents(WEB_PARITY_CHECKS), true);
    [$owner, $pages] = webParityWorld();
    $controls = [];
    $missing = [];
    foreach ($checks as $id => [$page, $want]) {
        if (! isset($controls[$page])) {
            $this->app['auth']->forgetGuards();
            SettingsContext::forget();
            $response = $this->actingAs($owner->fresh(), 'web')->get($pages[$page]);
            expect($response->getStatusCode())->toBe(200, "{$page} ({$pages[$page]}) answers in the new design");
            $controls[$page] = webParityControls((string) $response->getContent());
        }
        if (! webParityHas($controls[$page], $want)) {
            $missing[] = "{$id}: {$want} on {$page}";
        }
    }

    expect($missing)->toBe([]);
});

it('keeps the sheet and the checks in step: every web row this file proves has a check, and every check a row', function () {
    // (done rows, and partial rows for the part the new design has)
    $checks = json_decode((string) file_get_contents(WEB_PARITY_CHECKS), true);
    $rows = array_map('str_getcsv', array_slice(file(dirname(__DIR__, 4).'/docs/v2/audit/parity.csv', FILE_IGNORE_NEW_LINES), 1));
    $proved = collect($rows)->filter(fn (array $r): bool => ($r[1] ?? '') === 'web' && str_contains($r[7] ?? '', 'WebParityTest.php'))
        ->map(fn (array $r): array => [$r[0], $r[6]])->all();

    foreach ($proved as [$id, $status]) {
        // A partial row's check proves the part the new design has.
        expect($checks)->toHaveKey($id)->and(in_array($status, ['done', 'partial'], true))->toBeTrue("{$id} is {$status}");
    }
    expect(array_diff(array_keys($checks), array_column($proved, 0)))->toBe([]);
});

<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\SettingsContext;
use App\Models\CaptureSession;
use Livewire\Livewire;

/*
 * The web rows of docs/v2/audit/parity.csv (docs/PLAN_V2_PARITY.md section 1). Every web row whose test is this file has
 * a check in web-parity-checks.json, and it must hold in the new design:
 *  - control: an element with that text in one scope of the page (main: the page's own content, outside its navs and the
 *    settings menu; or the sidebar, top bar, avatar menu, workspace switcher, player);
 *  - html: the page's HTML has every given string (the frame's key handler and its target, a marker class);
 *  - widget: a Home card renders with the given words;
 *  - exportformats: the selection's Export offers every given format.
 * Every page read must be drawn by the v2.0 frame, and a done web row without a test fails the sheet.
 */

require_once __DIR__.'/Support/parity_world.php';

const WEB_PARITY_CHECKS = __DIR__.'/web-parity-checks.json';

function webParitySheet(): array
{
    $lines = file(dirname(__DIR__, 4).'/docs/v2/audit/parity.csv', FILE_IGNORE_NEW_LINES) ?: [];

    return array_values(array_filter(array_map('str_getcsv', array_slice($lines, 1)), fn (array $r): bool => ($r[1] ?? '') === 'web'));
}

/** Whether one check holds in [$html] (control and html checks only). */
function webParityHolds(array $check, string $html): bool
{
    return match ($check['type']) {
        'control' => array_key_exists($check['key'], webParityControls($html, $check['scope'])),
        'html' => collect($check['contains'])->every(fn (string $needle): bool => str_contains($html, $needle)),
        default => false,
    };
}

it('holds every web row\'s check in the new design, on pages drawn by the v2.0 frame', function () {
    $checks = json_decode((string) file_get_contents(WEB_PARITY_CHECKS), true);
    [$owner, $pages] = webParityWorld();
    webParityDesign(true);
    $html = [];
    $failed = [];
    foreach ($checks as $id => $check) {
        if (in_array($check['type'], ['control', 'html'], true)) {
            $page = $check['page'];
            if (! isset($html[$page])) {
                $this->app['auth']->forgetGuards();
                SettingsContext::forget();
                $response = $this->actingAs($owner->fresh(), 'web')->get($pages[$page]);
                expect($response->getStatusCode())->toBe(200, "{$page} answers")
                    ->and(webParityIsV2((string) $response->getContent()))->toBeTrue("{$page} is drawn by the v2.0 frame");
                $html[$page] = (string) $response->getContent();
            }
            if (! webParityHolds($check, $html[$page])) {
                $failed[] = "{$id} on {$page}: ".json_encode($check, JSON_UNESCAPED_UNICODE);
            }
        } elseif ($check['type'] === 'widget') {
            $this->actingAs($owner->fresh(), 'web');
            $widget = Livewire::test($check['class'])->assertOk();
            foreach ($check['see'] as $words) {
                $widget->assertSee($words);
            }
        } elseif ($check['type'] === 'exportformats') {
            // Each format is an item of the new Library's Export sub-menu, and downloads.
            $this->actingAs($owner->fresh(), 'web');
            $ids = CaptureSession::query()->orderBy('started_at')->limit(2)->pluck('id')->all();
            foreach ($check['formats'] as $format) {
                $page = Livewire::test(ListCaptureSessions::class)->callTableBulkAction('export'.ucfirst($format), $ids, data: ['view' => null]);
                if ($page->errors()->isNotEmpty()) {
                    $failed[] = "{$id}: Export ▸ {$format} refused";
                }
            }
        }
    }

    expect($failed)->toBe([]);
});

it('fails a check when the control is taken away (the checks are not empty matches)', function () {
    $checks = json_decode((string) file_get_contents(WEB_PARITY_CHECKS), true);
    [$owner, $pages] = webParityWorld();
    webParityDesign(true);
    $this->actingAs($owner->fresh(), 'web');
    $html = (string) $this->get($pages['recording'])->getContent();
    $revoke = collect($checks)->first(fn (array $c): bool => $c['type'] === 'control' && $c['page'] === 'recording' && $c['key'] === 'button|revoke');

    expect(webParityHolds($revoke, $html))->toBeTrue();
    // The same page without its Revoke buttons, and without the frame's key handler.
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    foreach (iterator_to_array((new DOMXPath($doc))->query('//button')) as $button) {
        if (trim($button->textContent) === 'Revoke') {
            $button->parentNode?->removeChild($button);
        }
    }
    $without = (string) $doc->saveHTML();
    expect(webParityHolds($revoke, $without))->toBeFalse()
        ->and(webParityHolds(['type' => 'html', 'contains' => ["e.key === '/'"]], str_replace("e.key === '/'", '', $html)))->toBeFalse()
        // A control in the settings menu is not one of the page's own.
        ->and(webParityHolds(['type' => 'control', 'scope' => 'main', 'key' => 'a|appearance'], (string) $this->get($pages['settings-appearance'])->getContent()))->toBeFalse();
});

it('keeps the sheet and the checks in step, and every done web row tested', function () {
    $checks = json_decode((string) file_get_contents(WEB_PARITY_CHECKS), true);
    $rows = webParitySheet();

    foreach ($rows as [$id, , , $feature, , , $status, $test]) {
        expect(in_array($status, ['done', 'partial', 'missing', 'retired-with-approval'], true))->toBeTrue("{$id}: {$status}");
        if ($status === 'done') {
            expect($test)->not->toBe('none', "{$id} ({$feature}) is done without a test");
        }
        if (str_contains($test, 'WebParityTest.php')) {
            expect($checks)->toHaveKey($id)->and(in_array($status, ['done', 'partial'], true))->toBeTrue("{$id} is {$status}");
        }
    }
    expect(array_diff(array_keys($checks), array_column($rows, 0)))->toBe([])
        ->and(count(array_unique(array_column($rows, 0))))->toBe(count($rows));
});

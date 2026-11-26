<?php

declare(strict_types=1);

use App\Filament\Support\SettingsContext;

/*
 * W-229 (axe empty-table-header): a table's row actions have a column heading a screen reader says, in the console's
 * language, where Filament leaves an empty one. The word is visually hidden (.el-actions-heading in theme.css, which
 * also keeps the column as narrow as before). Every table gets it from AppServiceProvider's Table::configureUsing;
 * the saved classic pages show it on each table with row actions (ClassicTablesGoldenTest, AdminClassicGoldenTest).
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner] = webParityWorld();
});

/** [$path]'s HTML for the owner, in the new design or classic, in [$language]. */
function actionsHeadingPage(object $test, bool $v2, string $language, string $path): string
{
    webParityDesign($v2);
    $test->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();

    return (string) $test->actingAs($test->owner->fresh(), 'web')->get($path)->assertOk()->getContent();
}

it('names the row actions column for a screen reader, with no empty heading left', function (bool $v2, string $language, string $word, string $path) {
    $html = actionsHeadingPage($this, $v2, $language, $path);

    expect($html)->toMatch('~<th\b[^>]*scope="col"[^>]*>\s*<span class="el-actions-heading">'.preg_quote($word, '~').'</span>\s*</th>~u')
        ->and($html)->not->toContain('fi-ta-actions-header-cell')
        ->and(substr_count($html, 'el-actions-heading'))->toBe(1);
})->with([
    'classic, English, Devices' => [false, 'en', 'Actions', '/app/devices'],
    'classic, English, Request links' => [false, 'en', 'Actions', '/app/request-links'],
    'new design, English, My links' => [true, 'en', 'Actions', '/app/shares'],
    'new design, Urdu, Devices' => [true, 'ur', 'اعمال', '/app/devices'],
    'new design, Urdu, Recently deleted' => [true, 'ur', 'اعمال', '/app/recently-deleted'],
]);

it('keeps the labelled heading as narrow as the empty one and hides its word only visually', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)->toMatch('~\.fi-ta-header-cell:has\(> \.el-actions-heading\) \{\s*width: calc\(var\(--spacing\) \* 1\);\s*padding: 0;\s*\}~')
        ->and($css)->toMatch('~\.el-actions-heading \{[^}]*position: absolute;[^}]*clip: rect\(0, 0, 0, 0\);[^}]*\}~')
        ->and($css)->not->toMatch('~\.el-actions-heading \{[^}]*(display: none|visibility: hidden)~');
});

<?php

declare(strict_types=1);

use App\Filament\Support\SettingsContext;

/*
 * W-209: the Usage page was hard-coded English, so the new frame in Urdu showed it in English. Its words now go through
 * the translations: in Urdu every heading, label and note is Urdu, and in English (and in classic) nothing changes.
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
});

function usagePage(object $test, bool $v2, string $language): string
{
    webParityDesign($v2);
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();

    return (string) $test->actingAs($test->owner->fresh(), 'web')->get('/app/usage')->assertOk()->getContent();
}

/** What the page says, without its markup. */
function usageWords(string $html): string
{
    return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? ''))) ?? '';
}

const USAGE_ENGLISH = ['Billable minutes', 'Estimated cost', 'Priced with the rates in Settings', 'Storage used', 'Signed-in devices',
    'Limits this month', 'Owners get a notification', 'Change limits and rates in Settings', 'By person', 'Last six months', 'Totals',
    'By device', 'Top projects', 'Per language', 'Download CSV'];

it('shows the Usage page in Urdu in the new frame: none of its English headings, labels or notes are left', function () {
    $words = usageWords(usagePage($this, true, 'ur'));
    foreach (USAGE_ENGLISH as $english) {
        expect($words)->not->toContain($english);
    }
    expect($words)->toContain('قابلِ بل منٹ')->toContain('اس مہینے کی حدیں')->toContain('فرد کے لحاظ سے')->toContain('استعمال');
});

it('keeps the Usage page\'s English words in English and in classic', function () {
    foreach ([[true, 'en'], [false, 'en']] as [$v2, $language]) {
        $words = usageWords(usagePage($this, $v2, $language));
        foreach (array_diff(USAGE_ENGLISH, ['Change limits and rates in Settings']) as $english) {
            expect($words)->toContain($english);
        }
    }
});

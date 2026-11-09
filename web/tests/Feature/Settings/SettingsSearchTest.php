<?php

declare(strict_types=1);

use App\Services\Accounts\SettingsIndex;
use App\Services\Accounts\SettingsRegistry;

/*
| v1.7: Settings search finds individual settings (the registry), by the start of a word, a keyword phrase, a Roman
| Urdu or Urdu word, a doubled letter folded, and only when nothing matches a small typo; never "probe" for "pro".
*/

function settingsSearch(string $query): array
{
    return (new SettingsIndex(SettingsRegistry::entries()))->search($query);
}

/** @return list<string> */
function settingIds(string $query): array
{
    return array_map(fn (array $hit): string => (string) $hit['entry']['id'], settingsSearch($query)['settings']);
}

it('finds each setting the golden queries name, first', function (string $query, string $first) {
    expect(settingIds($query)[0] ?? null)->toBe($first);
})->with([
    ['dark mode', 'appearance.theme'],
    ['dark', 'appearance.theme'],
    ['color', 'appearance.theme'],
    ['colour', 'appearance.theme'],
    ['ultra', 'plan.upgrade'],
    ['premium', 'plan.upgrade'],
    ['price', 'plan.upgrade'],
    ['upgrade', 'plan.upgrade'],
    ['pro', 'plan.upgrade'],
    ['package', 'plan'],
    ['plan', 'plan'],
    ['payment', 'plan.payment_details'],
    ['bank', 'plan.payment_details'],
    ['iban', 'plan.payment_details'],
    ['jazzcash', 'plan.payment_details'],
    ['raseed', 'plan.receipt'],
    ['screenshot payment', 'plan.receipt'],
    ['receipt', 'plan.receipt'],
    ['promo', 'plan.promo'],
    ['discount', 'plan.promo'],
    ['sign out', 'signout'],
    ['logout', 'signout'],
    ['delete account', 'privacy.delete_account'],
    ['2fa', 'security.two_step'],
    ['otp', 'security.two_step'],
    ['two step', 'security.two_step'],
    ['2 step', 'security.two_step'],
    ['zuban', 'transcription.language'],
    ['roman urdu', 'transcription.language'],
    ['groq', 'key.saved'],
    ['transcription', 'key'],
    ['transcrption', 'key'],
    ['Theme', 'appearance.theme'],
    ['زبان', 'transcription.language'],
]);

it('puts Upgrade first for "pro", then Profile, and never a probe or anything typo-matched', function () {
    $ids = settingIds('pro');
    expect($ids[0])->toBe('plan.upgrade')->and($ids[1])->toBe('profile');
});

it('orders "sign out" by depth and "payment" by the shorter title', function () {
    expect(array_slice(settingIds('sign out'), 0, 2))->toBe(['signout', 'security.signout_all'])
        ->and(array_slice(settingIds('payment'), 0, 3))->toBe(['plan.payment_details', 'plan.history', 'plan.receipt'])
        ->and(array_slice(settingIds('transcription'), 0, 2))->toBe(['key', 'transcription.language'])
        ->and(array_slice(settingIds('transcrption'), 0, 2))->toBe(['key', 'transcription.language']);
});

it('scores by the tier reached, less 15 per level and 40 more for a page', function () {
    $scores = [];
    foreach (settingsSearch('dark mode')['settings'] as $hit) {
        $scores[$hit['entry']['id']] = $hit['score'];
    }
    expect($scores['appearance.theme'])->toBe(370);
    $scores = [];
    foreach (settingsSearch('pro')['settings'] as $hit) {
        $scores[$hit['entry']['id']] = $hit['score'];
    }
    expect($scores['profile'])->toBe(1025)->and($scores['plan.promo'])->toBe(970);
});

it('matches values never: "on" finds nothing, and a nonsense word shows no closest matches', function () {
    expect(settingsSearch('on')['settings'])->toBe([])
        ->and(settingsSearch('xyzzy'))->toBe(['settings' => [], 'help' => [], 'closest' => []]);
});

it('never fuzzy-matches Roman Urdu: "kaal" finds nothing and Theme is nowhere', function () {
    $result = settingsSearch('kaal');
    expect($result['settings'])->toBe([])
        ->and(array_column($result['closest'], 'id'))->not->toContain('appearance.theme');
});

it('matches Roman Urdu and Urdu words whole, never by their start', function () {
    expect(settingIds('dark'))->not->toContain('plan.request')
        ->and(settingIds('darkhwast'))->toContain('plan.request')
        ->and(settingIds('awaz'))->toBe([]);
});

it('needs two characters, and every word must match', function () {
    expect(settingsSearch('d')['settings'])->toBe([])
        ->and(settingIds('dark nothingness'))->toBe([]);
});

it('finds questions in Help apart from settings', function () {
    $result = settingsSearch('dark mode');
    expect(array_map(fn (array $h): string => $h['entry']['id'], $result['help']))->toContain('help.article.dark_mode')
        ->and(settingIds('dark mode'))->not->toContain('help.article.dark_mode');
});

it('normalises accents, case, Urdu letter forms and punctuation, and folds doubled letters', function () {
    expect(SettingsIndex::normalize('Sign-in & Security'))->toBe('sign in security')
        ->and(SettingsIndex::normalize('Colour'))->toBe('color')
        ->and(SettingsIndex::normalize('زبان'))->toBe('زبان')
        ->and(SettingsIndex::normalize('كيا'))->toBe('کیا')
        ->and(SettingsIndex::fold('aawaz'))->toBe('awaz')
        ->and(SettingsIndex::fold('pro'))->toBe('pro')
        ->and(SettingsIndex::distance('transcrption', 'transcription'))->toBe(1)
        ->and(SettingsIndex::distance('ab', 'ba'))->toBe(1);
});

it('writes the path as a breadcrumb and marks the matched starts', function () {
    $theme = SettingsRegistry::byId()['appearance.theme'];
    expect(SettingsIndex::breadcrumb($theme))->toBe('Settings › Appearance › Theme')
        ->and(SettingsIndex::breadcrumb(SettingsRegistry::byId()['appearance']))->toBe('Settings › Appearance')
        ->and(SettingsIndex::marked('Lock after', 'lock'))->toBe('<mark>Lock</mark> after')
        ->and(SettingsIndex::marked('<b>', 'x'))->toBe('&lt;b&gt;');
});

it('keeps every registry id unique and every title short', function () {
    $ids = array_column(SettingsRegistry::entries(), 'id');
    expect(count($ids))->toBe(count(array_unique($ids)));
    foreach (SettingsRegistry::entries() as $entry) {
        if ($entry['kind'] !== 'help') {
            expect(mb_strlen((string) $entry['title']))->toBeLessThanOrEqual(31);
        }
    }
    foreach (SettingsRegistry::PAGES as $page => $id) {
        expect(SettingsRegistry::byId())->toHaveKey($id)->and(SettingsRegistry::byId()[$id]['page'])->toBe($page);
    }
});

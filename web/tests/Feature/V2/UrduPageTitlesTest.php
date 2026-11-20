<?php

declare(strict_types=1);

use App\Filament\Pages\RequestLinksPage;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Support\SettingsContext;
use Filament\Actions\Action;
use Livewire\Livewire;

/*
 * W-207: in the new frame the Devices and Request links pages read their title and their header button (with its
 * window) in the console's language, as My links does; English reads as classic, and classic keeps its words.
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
});

/** The page as drawn, in the new frame (English or Urdu) or in classic. */
function titledPage(object $test, string $page, bool $v2, string $language = 'en'): string
{
    webParityDesign($v2);
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();

    return (string) $test->actingAs($test->owner->fresh(), 'web')->get($test->pages[$page])->assertOk()->getContent();
}

dataset('titled pages', [
    'devices' => ['devices', 'Devices', 'ڈیوائسز', 'Sign in a device', 'ڈیوائس سائن اِن کریں'],
    'request links' => ['request-links', 'Request links', 'درخواست کے لنکس', 'New request link', 'نیا درخواست کا لنک'],
]);

it('titles the page and names its header button in Urdu in the new frame, and in classic\'s English otherwise', function (string $page, string $title, string $urduTitle, string $button, string $urduButton) {
    $heading = fn (string $words): string => '~<h1[^>]*>\s*'.preg_quote($words, '~').'\s*</h1>~u';
    $ur = titledPage($this, $page, true, 'ur');
    expect($ur)->toMatch($heading($urduTitle))->toMatch('~<title>\s*'.preg_quote($urduTitle, '~').'~u')->toContain($urduButton)
        ->not->toMatch($heading($title))->not->toContain('>'.$button.'<');

    foreach ([true, false] as $v2) {
        $en = titledPage($this, $page, $v2);
        expect($en)->toMatch($heading($title))->toContain($button)->not->toContain($urduButton);
    }
})->with('titled pages');

it('words the header button\'s window in Urdu in the new frame, and in classic\'s English in classic', function () {
    $as = function (bool $v2, string $language): void {
        webParityDesign($v2);
        $prefs = is_array($this->owner->console_preferences) ? $this->owner->console_preferences : [];
        $prefs['shell'] = ['app_language' => $language];
        $this->owner->forceFill(['console_preferences' => $prefs])->save();
        SettingsContext::forget();
        app('auth')->forgetGuards();
        $this->actingAs($this->owner->fresh(), 'web');
        app()->setLocale($v2 ? $language : 'en');
    };

    $as(true, 'ur');
    Livewire::test(ListDevices::class)->assertActionExists('pair', fn (Action $a): bool => $a->getModalHeading() === 'فون یا کمپیوٹر سائن اِن کریں'
        && $a->getModalCancelActionLabel() === 'ہو گیا' && str_contains((string) $a->getModalDescription(), 'QR کوڈ اسکین کریں'));
    Livewire::test(RequestLinksPage::class)->assertActionExists('create', fn (Action $a): bool => $a->getModalSubmitActionLabel() === 'لنک بنائیں'
        && str_contains((string) $a->getModalDescription(), 'بغیر اکاؤنٹ کے'));

    $as(false, 'en');
    Livewire::test(ListDevices::class)->assertActionExists('pair', fn (Action $a): bool => $a->getModalHeading() === 'Sign in a phone or computer'
        && $a->getModalCancelActionLabel() === 'Done' && str_starts_with((string) $a->getModalDescription(), 'Scan the QR code in the '));
    Livewire::test(RequestLinksPage::class)->assertActionExists('create', fn (Action $a): bool => $a->getModalSubmitActionLabel() === 'Make the link');
});

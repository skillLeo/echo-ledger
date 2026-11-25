<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * W-237: in the new recording page's More menu, Download is a menu item like every other one (its icon and words at the
 * start of the row), in English and in Urdu; it was drawn as a centred grey button. The classic page's header keeps its
 * Download button.
 */

beforeEach(function () {
    Fx::fakeRecordingsDisk();
    Filament::setCurrentPanel('app');
    $this->owner = Fx::user();
    $this->call = Fx::session($this->owner, Fx::device($this->owner), ['title' => 'Payment terms']);
    Fx::readyRecording($this->call, 'audio of the call');
    Fx::transcript($this->call, 'The proforma is ready.');
});

/** The element whose click opens [$action] on the recording page, in [$language], new design on or off. */
function moreMenuElement(object $test, string $action, string $language, bool $v2 = true): string
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, $v2, 0, [], [], $v2 ? 'everyone' : 'off');
    }
    $test->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
    $test->actingAs($test->owner->fresh(), 'web');
    app()->setLocale($language);
    $html = (string) $test->get(CaptureSessionResource::getUrl('view', ['record' => $test->call]))->assertOk()->getContent();

    return preg_match('~<(?:button|a)\b[^>]*wire:click="mountAction\(\''.$action.'\'[^>]*>~', $html, $m) === 1 ? $m[0] : '';
}

it('draws More\'s Download as a menu item like WhatsApp, in English and in Urdu', function (string $language) {
    $download = moreMenuElement($this, 'download', $language);
    expect($download)->toContain('fi-dropdown-list-item')->not->toContain('fi-ac-btn-action')
        ->and(moreMenuElement($this, 'whatsapp', $language))->toContain('fi-dropdown-list-item');
})->with(['en', 'ur']);

it('keeps the classic page\'s Download a button in its header', function () {
    // A button's view (an icon button under sm, labelled from sm), never a menu item.
    expect(moreMenuElement($this, 'download', 'en', v2: false))->toContain('fi-ac-btn-action')->not->toContain('fi-dropdown-list-item');
});

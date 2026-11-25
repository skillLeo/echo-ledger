<?php

declare(strict_types=1);

use App\Filament\Support\RtlIcons;
use App\Services\ActivityFeed;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * W-234: right to left (Urdu) the icons that point a way or read as text are mirrored, as on the desktop and the phone:
 * Settings' rows (›) and back links (‹), the upgrade pages' way back, the crumbs, Help's "?", a chat bubble, a missed
 * call, sign out (RtlIcons, the new design's v2-icon-flip). In English and in classic they draw byte for byte as
 * before (the goldens hold classic).
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->owner = Fx::user(['name' => 'Saif Hassam', 'email' => 'saif@example.com']);
});

function rtlIconsConsole(string $language, bool $v2 = true): void
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, $v2, 0, [], [], $v2 ? 'everyone' : 'off');
    }
    test()->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
    test()->actingAs(test()->owner->fresh(), 'web');
}

it('mirrors the icons that point a way or read as text, by name in any heroicon style, and no other', function () {
    foreach (['heroicon-o-chevron-right', 'heroicon-m-chevron-right', 'heroicon-o-chevron-left', 'heroicon-o-question-mark-circle',
        'heroicon-o-chat-bubble-left-ellipsis', 'heroicon-o-chat-bubble-bottom-center-text', 'heroicon-o-chat-bubble-left-right',
        'heroicon-o-phone-x-mark', 'heroicon-o-arrow-left-start-on-rectangle'] as $icon) {
        expect(RtlIcons::mirrored($icon))->toBeTrue($icon);
    }
    // The media player's skip back and forward, and icons with no way, stay as they are.
    foreach (['heroicon-o-backward', 'heroicon-o-forward', 'heroicon-o-check', 'heroicon-o-x-mark', 'heroicon-o-arrow-top-right-on-square'] as $icon) {
        expect(RtlIcons::mirrored($icon))->toBeFalse($icon);
    }
    // Left to right (no console here) nothing is added: the attributes as given.
    expect(RtlIcons::of('heroicon-o-chevron-right', ['class' => 'el-set-chevron', 'aria-hidden' => 'true']))
        ->toBe(['heroicon-o-chevron-right', ['class' => 'el-set-chevron', 'aria-hidden' => 'true']]);
});

it('points Settings\' rows and back link the reading way in Urdu, and leaves English as it was', function () {
    rtlIconsConsole('ur');
    $ur = (string) $this->get('/app/settings')->assertOk()->getContent();
    expect(substr_count($ur, 'class="el-set-chevron v2-icon-flip"'))->toBeGreaterThan(3)
        ->and($ur)->not->toContain('class="el-set-chevron"');
    // A page of Settings: its way back (‹) and its Help row's "?" too.
    $page = (string) $this->get('/app/settings/appearance')->assertOk()->getContent();
    expect($page)->toMatch('~class="el-set-back el-set-only-narrow"><svg aria-hidden="true" class="v2-icon-flip" ~')
        ->toContain('class="el-set-icon v2-icon-flip"');

    rtlIconsConsole('en');
    $en = (string) $this->get('/app/settings')->assertOk()->getContent();
    expect($en)->toContain('class="el-set-chevron"')->not->toContain('el-set-chevron v2-icon-flip')->not->toContain('el-set-icon v2-icon-flip');
});

it('mirrors a missed call on Activity in Urdu only', function () {
    app(ActivityFeed::class)->post($this->owner, 'missed_recording', 'Missed recording', 'WhatsApp call · 5:12', null, 'heroicon-o-phone-x-mark');

    rtlIconsConsole('ur');
    expect((string) $this->get('/app/activity')->assertOk()->getContent())->toMatch('~<span class="el-tile el-tile-amber"><svg aria-hidden="true" class="v2-icon-flip" ~');
    rtlIconsConsole('en');
    expect((string) $this->get('/app/activity')->assertOk()->getContent())->toMatch('~<span class="el-tile el-tile-amber"><svg aria-hidden="true" xmlns=~');
    rtlIconsConsole('en', false);
    expect((string) $this->get('/app/activity')->assertOk()->getContent())->toMatch('~<span class="el-tile el-tile-amber"><svg aria-hidden="true" xmlns=~');
});

<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Support\RtlIcons;
use App\Filament\Support\SharingActions;
use App\Filament\Support\WhatsAppActions;
use App\Livewire\ActivityBell;
use App\Models\MissedRecording;
use App\Services\ActivityFeed;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * W-234: right to left (Urdu) the icons that point a way or read as text are mirrored, as on the desktop and the phone:
 * Settings' rows (›) and back links (‹), the upgrade pages' way back, the crumbs, Help's "?", a chat bubble, a missed
 * call, sign out (RtlIcons, the new design's v2-icon-flip). In English and in classic they draw byte for byte as
 * before (the goldens hold classic). W-235: the icons Filament draws itself from a Heroicon or a name (an action's, a
 * notification's) are mirrored the same way (RtlIcons::icon); an app's own icon never is (D4 §7).
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

it('mirrors the rail\'s Help "?" and Ask\'s chat bubble in Urdu, and draws them as before in English', function () {
    rtlIconsConsole('ur');
    $ur = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($ur)->toMatch('~data-module="help"[^>]*>\s*<span class="v2-rail-icon"><svg class="v2-icon v2-icon-flip"~')
        ->toMatch('~data-module="ask"[^>]*>\s*<span class="v2-rail-icon"><svg class="v2-icon v2-icon-flip"~');
    rtlIconsConsole('en');
    $en = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($en)->toMatch('~data-module="help"[^>]*>\s*<span class="v2-rail-icon"><svg class="v2-icon"~')
        ->toMatch('~data-module="ask"[^>]*>\s*<span class="v2-rail-icon"><svg class="v2-icon"~')
        ->not->toMatch('~data-module="ask"[^>]*>\s*<span class="v2-rail-icon"><svg class="v2-icon v2-icon-flip"~');
});

/** Whether [$icon] is given as markup with the mirror's class (RtlIcons::icon), which Filament draws in its own icon. */
function drawnMirrored(mixed $icon): bool
{
    return $icon instanceof Htmlable && str_starts_with(trim($icon->toHtml()), '<svg class="v2-icon-flip"');
}

it('mirrors the icons Filament draws itself in Urdu: Ask, WhatsApp, Send, Restore, a missed call\'s notification; never an app\'s own icon (W-235)', function () {
    $call = Fx::session($this->owner, Fx::device($this->owner));
    MissedRecording::query()->create(['workspace_id' => $call->workspace_id, 'user_id' => $this->owner->id, 'device_id' => $call->device_id,
        'client_ref' => 'missed-1', 'platform' => 'android', 'source_app' => 'whatsapp', 'call_started_at' => now()->subHour(), 'call_duration_s' => 312, 'reason' => 'permission']);
    $missed = $this->owner->notifications()->get()->sole(fn ($n): bool => ActivityFeed::kindOf($n) === 'missed_recording');
    $bell = fn (): string => (new ActivityBell)->getNotification($missed)->inline()->toHtml();

    rtlIconsConsole('ur');
    expect(drawnMirrored(RtlIcons::icon(Heroicon::OutlinedChatBubbleLeftEllipsis)))->toBeTrue()
        ->and(drawnMirrored(RtlIcons::icon('heroicon-o-phone-x-mark')))->toBeTrue()
        ->and(drawnMirrored(WhatsAppActions::send(fn () => $call)->getIcon()))->toBeTrue()
        ->and(drawnMirrored(SharingActions::send(fn () => $call)->getIcon()))->toBeTrue()
        ->and(drawnMirrored(RtlIcons::icon(Heroicon::OutlinedArrowUturnLeft)))->toBeTrue()
        ->and(drawnMirrored(RtlIcons::icon(Heroicon::OutlinedArrowsUpDown)))->toBeTrue()
        // Filament's sized icon holds it (.fi-icon > svg takes its size), as for any icon given as markup.
        ->and($bell())->toMatch('~<span class="(?=[^"]*\bfi-icon\b)[^"]*\bfi-no-notification-icon\b[^"]*">\s*<svg class="v2-icon-flip" ~');
    // Logos, brand marks and a recording's source icon never mirror (D4 §7): the app's own chat bubble stays as it is,
    // as on the tile, the recording's header, the desktop and the phone.
    expect(SourceApp::WhatsApp->getIcon())->toBe(Heroicon::OutlinedChatBubbleLeftRight)
        ->and(SourceApp::Telegram->getIcon())->toBe(Heroicon::OutlinedChatBubbleLeftRight)
        // An icon with no way stays as it is; so does a solid Heroicon, whose drawing changes with the size Filament
        // picks, and a name that is not a heroicon's (no file to draw: Urdu never fails where English works).
        ->and(RtlIcons::icon(Heroicon::OutlinedCheck))->toBe(Heroicon::OutlinedCheck)
        ->and(RtlIcons::icon(Heroicon::ChatBubbleLeftRight))->toBe(Heroicon::ChatBubbleLeftRight)
        ->and(RtlIcons::icon('chevron-right'))->toBe('chevron-right')
        ->and(RtlIcons::icon(null))->toBeNull();

    // English, and classic in Urdu: as before, the Heroicon or the name itself.
    foreach ([['en', true], ['ur', false]] as [$language, $v2]) {
        rtlIconsConsole($language, $v2);
        expect(WhatsAppActions::send(fn () => $call)->getIcon())->toBe(Heroicon::OutlinedChatBubbleLeftRight)
            ->and(SharingActions::send(fn () => $call)->getIcon())->toBe(Heroicon::OutlinedPaperAirplane)
            ->and(RtlIcons::icon(Heroicon::OutlinedArrowUturnLeft))->toBe(Heroicon::OutlinedArrowUturnLeft)
            ->and(RtlIcons::icon('heroicon-o-phone-x-mark'))->toBe('heroicon-o-phone-x-mark')
            ->and($bell())->toMatch('~<svg class="(?=[^"]*\bfi-icon\b)[^"]*\bfi-no-notification-icon\b~')->not->toContain('v2-icon-flip');
    }
});

it('draws a recording\'s Send and WhatsApp mirrored inside Filament\'s own icon on the real page in Urdu, never its app\'s mark, and as before in English (W-235)', function () {
    Fx::fakeRecordingsDisk();
    $call = Fx::session($this->owner, Fx::device($this->owner), ['title' => 'Payment terms', 'source_app' => 'whatsapp']);
    Fx::readyRecording($call, 'audio of the call');
    $page = function (string $language) use ($call): string {
        rtlIconsConsole($language);
        app()->setLocale($language);

        return (string) $this->get(CaptureSessionResource::getUrl('view', ['record' => $call]))->assertOk()->getContent();
    };
    // Markup given by RtlIcons::icon starts with its class; a Blade's @svg puts aria-hidden first.
    $inFilamentIcon = '~<span\b[^>]*\bclass="(?=[^"]*\bfi-icon\b)[^"]*">\s*<svg class="v2-icon-flip" ~';
    // Within one menu item (never across </button>): its mirrored icon, then its label.
    $item = fn (string $label): string => '~<svg class="v2-icon-flip" (?:(?!</button>).)*<span class="fi-dropdown-list-item-label">'.$label.'</span>~s';

    $ur = $page('ur');
    expect(preg_match_all($inFilamentIcon, $ur))->toBeGreaterThanOrEqual(2)
        ->and($ur)->toMatch($item('ساتھی کو بھیجیں'))
        // The bare brand name stays Latin in every language (LEAD DECISION 26).
        ->and($ur)->toMatch($item('WhatsApp'))
        // The app's own mark on the meta line never mirrors (D4 §7).
        ->and($ur)->toMatch('~<span class="v2-rec-meta">\s*<svg class="v2-icon" viewBox~');

    $en = $page('en');
    expect($en)->not->toContain('<svg class="v2-icon-flip"')
        ->toMatch('~<span class="v2-rec-meta">\s*<svg class="v2-icon" viewBox~')->toContain('Send to a teammate');
});

it('mirrors the right-click menu\'s Send to WhatsApp… (an action) in Urdu, but never a row\'s app mark (W-235)', function () {
    // Telegram has its own mark; Signal has none, so its row draws the chat glyph as the fallback (source-logo).
    $device = Fx::device($this->owner);
    $telegram = Fx::session($this->owner, $device, ['title' => 'Telegram call', 'source_app' => 'telegram']);
    $signal = Fx::session($this->owner, $device, ['title' => 'Signal call', 'source_app' => 'signal']);
    $send = '<template data-icon="SendIcon"><svg class="v2-icon v2-icon-flip"';
    /** The source mark of [$call]'s Library row, from its data-recording to the end of its tile's button. */
    $mark = fn (string $html, $call): string => preg_match('~data-recording="'.$call->id.'"(?:(?!</button>).)*</button>~s', $html, $m) === 1 ? $m[0] : '';

    rtlIconsConsole('ur');
    $ur = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($ur)->toContain($send)
        ->and($mark($ur, $signal))->toContain('<span class="v2-source-fallback"><svg class="v2-icon" viewBox')->not->toContain('v2-icon-flip')
        ->and($mark($ur, $telegram))->toContain('<svg class="v2-source-mark"')->not->toContain('v2-icon-flip');
    // Telegram's recording page: its meta line draws the chat glyph (V2RecordingRow::glyph), never mirrored.
    expect((string) $this->get(CaptureSessionResource::getUrl('view', ['record' => $telegram]))->assertOk()->getContent())
        ->toMatch('~<span class="v2-rec-meta">\s*<svg class="v2-icon" viewBox~');

    rtlIconsConsole('en');
    $en = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($en)->toContain('<template data-icon="SendIcon"><svg class="v2-icon"')->not->toContain($send)
        ->and($mark($en, $signal))->toContain('<span class="v2-source-fallback"><svg class="v2-icon" viewBox');
});

it('points the Library toolbar\'s sort icon the reading way in Urdu (W-235, D-231)', function () {
    $toolbar = function (string $language): string {
        app(InterfaceFlags::class)->set('ui_v2.shell', true, 0, [], [], 'everyone');
        test()->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
        test()->actingAs(test()->owner->fresh(), 'web');
        $html = (string) test()->get('/app/sessions')->assertOk()->getContent();

        return preg_match('~data-menu="sort"[^>]*>\s*<svg[^>]*>~', $html, $m) === 1 ? $m[0] : '';
    };
    expect($toolbar('ur'))->toContain('class="v2-icon-flip"')
        ->and($toolbar('en'))->not->toBe('')->not->toContain('v2-icon-flip');
});

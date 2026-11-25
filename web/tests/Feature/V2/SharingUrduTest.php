<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Services\InterfaceFlags;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * U9d: the sharing actions (App\Filament\Support\SharingActions, WhatsAppActions) in the console's language. On the new
 * recording page in Urdu, More's Send to a teammate, Sent to and WhatsApp read in Urdu, and so do Share's window and its
 * notice. English reads exactly as before (ClassicGoldenTest, ClassicTablesGoldenTest).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    $this->owner = Fx::user();
    $this->recording = Fx::session($this->owner, Fx::device($this->owner), ['title' => 'Payment terms']);
    Fx::readyRecording($this->recording, 'audio of the call');
    Fx::transcript($this->recording, 'The proforma is ready.');
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
});

/** The recording page as the person sees it, in English or Urdu. */
function sharingPage(object $test, string $language): mixed
{
    $prefs = $test->owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    $test->actingAs($test->owner->fresh(), 'web');
    app()->setLocale($language);

    return Livewire::test(ViewCaptureSession::class, ['record' => $test->recording->id]);
}

it('reads More\'s Send to a teammate, Sent to and WhatsApp in Urdu, and Share\'s window and notice', function () {
    $page = sharingPage($this, 'ur');
    $page->assertActionExists('send', fn (Action $a): bool => $a->getLabel() === 'ساتھی کو بھیجیں')
        ->assertActionExists('sentTo', fn (Action $a): bool => $a->getLabel() === 'کس کو بھیجا')
        ->assertActionExists('whatsapp', fn (Action $a): bool => $a->getLabel() === 'WhatsApp' && $a->getModalHeading() === 'واٹس ایپ پر بھیجیں')
        ->assertActionExists('share', fn (Action $a): bool => $a->getModalHeading() === 'یہ ریکارڈنگ شیئر کریں' && $a->getModalSubmitActionLabel() === 'لنک بنائیں');

    $page->callAction('share', data: ['audience' => 'public', 'expires' => '604800'])
        ->assertNotified('شیئر لنک تیار ہے۔ ابھی کاپی کر لیں: یہ صرف ایک بار دکھایا جاتا ہے۔');
});

it('keeps the English words exactly as they were', function () {
    sharingPage($this, 'en')
        ->assertActionExists('send', fn (Action $a): bool => $a->getLabel() === 'Send to a teammate')
        ->assertActionExists('sentTo', fn (Action $a): bool => $a->getLabel() === 'Sent to')
        ->assertActionExists('share', fn (Action $a): bool => $a->getModalHeading() === 'Share this recording')
        ->callAction('share', data: ['audience' => 'public', 'expires' => '604800'])
        ->assertNotified('Share link ready. Copy it now: it is shown only once.');
    expect(trans_choice('Sent to :count address|Sent to :count addresses', 1, ['count' => 1]))->toBe('Sent to 1 address')
        ->and(trans_choice('Sent to :count address|Sent to :count addresses', 3, ['count' => 3]))->toBe('Sent to 3 addresses');
});

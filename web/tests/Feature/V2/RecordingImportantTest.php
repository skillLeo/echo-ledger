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
 * WEB-REC-009 (U9, docs/PLAN_V2_PARITY.md section 4): the new recording page's More offers Mark important, and Unmark
 * important once it is flagged, through the same update as PATCH /api/sessions/{id}; the flag shows under the title.
 * Classic's recording page has neither (ClassicGoldenTest).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    $this->owner = Fx::user();
    $this->recording = Fx::session($this->owner, Fx::device($this->owner), ['title' => 'Payment terms']);
    Fx::transcript($this->recording, 'The proforma is ready.');
    Filament::setCurrentPanel('app');
    $this->actingAs($this->owner->fresh(), 'web');
});

it('marks the recording important from More, flags it under the title, and offers Unmark important then', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $page = Livewire::test(ViewCaptureSession::class, ['record' => $this->recording->id]);
    $page->assertActionExists('important', fn (Action $a): bool => $a->getLabel() === 'Mark important');
    expect($page->html())->not->toContain('data-important-flag');

    Carbon::setTestNow(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
    $page->callAction('important')->assertNotified('Marked important');
    expect($this->recording->fresh()->important)->toBeTrue()
        // The update the API's PATCH makes: a new updated_at, so the apps pick the flag up on their next sync.
        ->and($this->recording->fresh()->updated_at->eq(Carbon::parse('2026-11-17 12:00:00')))->toBeTrue();

    $page = Livewire::test(ViewCaptureSession::class, ['record' => $this->recording->id]);
    $page->assertActionExists('important', fn (Action $a): bool => $a->getLabel() === 'Unmark important');
    expect((string) $this->get('/app/sessions/'.$this->recording->id)->getContent())->toContain('data-important-flag');
    $page->callAction('important')->assertNotified('No longer important');
    expect($this->recording->fresh()->important)->toBeFalse();
});

it('reads Mark important in Urdu when the console is in Urdu', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $prefs = $this->owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => 'ur'];
    $this->owner->forceFill(['console_preferences' => $prefs])->save();
    $this->actingAs($this->owner->fresh(), 'web');
    app()->setLocale('ur');

    Livewire::test(ViewCaptureSession::class, ['record' => $this->recording->id])
        ->assertActionExists('important', fn (Action $a): bool => $a->getLabel() === 'اہم نشان لگائیں');
});

it('leaves the classic recording page without Mark important', function () {
    expect((string) $this->get('/app/sessions/'.$this->recording->id)->assertOk()->getContent())->not->toContain('Mark important')->not->toContain('data-important-flag');
});

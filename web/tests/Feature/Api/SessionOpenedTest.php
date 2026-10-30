<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Models\CaptureSession;
use App\Models\SessionOpen;
use App\Models\User;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/*
 * The unopened dot (v1.3): the server keeps who has opened which recording, per user; a device says so with
 * POST /api/sessions/{id}/opened, the console when the recording's page is viewed, and the library tells each user.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    $this->session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'workspace_id' => $this->device->workspace_id]);
});

function openedInLibrary(object $test): ?bool
{
    $item = collect($test->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $test->session->id);

    return $item['opened'] ?? null;
}

it('lists a recording as not opened until the device says it was opened', function () {
    expect(openedInLibrary($this))->toBeFalse();

    $updatedAt = $this->session->refresh()->updated_at;
    $this->postJson("/api/sessions/{$this->session->id}/opened")->assertNoContent();
    $this->postJson("/api/sessions/{$this->session->id}/opened")->assertNoContent();

    expect(openedInLibrary($this))->toBeTrue()
        ->and(SessionOpen::query()->count())->toBe(1)
        ->and($this->session->refresh()->updated_at->equalTo($updatedAt))->toBeTrue('opening never changes the recording itself');
});

it('counts only the asking user: another user opening it changes nothing', function () {
    $other = User::factory()->create();
    SessionOpen::mark($this->session->id, $other->id);

    expect(openedInLibrary($this))->toBeFalse();
});

it('does not open a recording of another workspace', function () {
    $foreign = CaptureSession::factory()->create();

    $this->postJson("/api/sessions/{$foreign->id}/opened")->assertNotFound();
    expect(SessionOpen::query()->count())->toBe(0);
});

it('counts viewing the recording in the console as opening it', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id]);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->assertOk();

    expect(SessionOpen::query()->where('user_id', $admin->id)->where('capture_session_id', $session->id)->exists())->toBeTrue();
});

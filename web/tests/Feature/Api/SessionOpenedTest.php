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

/*
 * Mark as unread (contract 2.0.0-t, UX-22): DELETE /api/sessions/{id}/opened takes back the asking person's own open.
 */

function unreadCount(object $test): int
{
    return (int) $test->getJson('/api/library/facets')->assertOk()->json('library.unread');
}

it('marks a recording unread again: opened false and library.unread one more, the recording itself untouched', function () {
    $this->postJson("/api/sessions/{$this->session->id}/opened")->assertNoContent();
    $before = unreadCount($this);
    $updatedAt = $this->session->refresh()->updated_at;

    $this->deleteJson("/api/sessions/{$this->session->id}/opened")->assertNoContent();

    expect(openedInLibrary($this))->toBeFalse()
        ->and(unreadCount($this))->toBe($before + 1)
        ->and(SessionOpen::query()->where('capture_session_id', $this->session->id)->count())->toBe(0)
        ->and($this->session->refresh()->updated_at->equalTo($updatedAt))->toBeTrue('marking unread never changes the recording itself');
});

it('is idempotent: a recording not opened, or already unread, answers 204 again', function () {
    $this->deleteJson("/api/sessions/{$this->session->id}/opened")->assertNoContent();
    $this->deleteJson("/api/sessions/{$this->session->id}/opened")->assertNoContent();

    expect(openedInLibrary($this))->toBeFalse();
});

it('takes back only the asking person\'s open: another person\'s stays', function () {
    $other = User::factory()->create();
    SessionOpen::mark($this->session->id, $other->id);
    SessionOpen::mark($this->session->id, $this->device->user_id);

    $this->deleteJson("/api/sessions/{$this->session->id}/opened")->assertNoContent();

    expect(SessionOpen::query()->where('capture_session_id', $this->session->id)->pluck('user_id')->all())->toBe([$other->id]);
});

it('answers 404 for another workspace\'s recording, and leaves its opens alone', function () {
    $foreign = CaptureSession::factory()->create();
    SessionOpen::mark($foreign->id, $foreign->user_id);

    $this->deleteJson("/api/sessions/{$foreign->id}/opened")->assertNotFound();
    expect(SessionOpen::query()->where('capture_session_id', $foreign->id)->count())->toBe(1);
});

it('answers 404 for a recording of a teammate the person cannot read, the same as POST', function () {
    $teammate = User::factory()->create();
    $hidden = CaptureSession::factory()->create(['user_id' => $teammate->id, 'workspace_id' => $this->device->workspace_id]);
    SessionOpen::mark($hidden->id, $this->device->user_id);

    $this->postJson("/api/sessions/{$hidden->id}/opened")->assertNotFound();
    $this->deleteJson("/api/sessions/{$hidden->id}/opened")->assertNotFound();
    expect(SessionOpen::query()->where('capture_session_id', $hidden->id)->count())->toBe(1);
});

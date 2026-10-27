<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\CaptureSessions\RelationManagers\ShareLinksRelationManager;
use App\Models\CaptureSession;
use Filament\Facades\Filament;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/*
 * A recording that captured no sound (v1.1.2): "No audio" with what to do, never a failure card, no player, no sharing.
 */

function silentSession(int $userId, string $code = 'no_audio'): CaptureSession
{
    $session = CaptureSession::factory()->create(['user_id' => $userId, 'status' => SessionStatus::Failed]);
    $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed,
        'error' => 'No audio was captured in this recording.', 'error_code' => $code]);

    return $session;
}

it('tells a recording without sound apart from a real failure', function () {
    [$device] = $this->enroll(platform: 'android');
    $silent = silentSession($device->user_id);
    $failed = silentSession($device->user_id, 'provider_rejected');

    expect($silent->hasNoAudio())->toBeTrue()->and($failed->hasNoAudio())->toBeFalse();

    $completed = CaptureSession::factory()->create(['user_id' => $device->user_id, 'status' => SessionStatus::Completed]);
    $completed->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'Hi']);
    expect($completed->hasNoAudio())->toBeFalse();
});

it('sends the failure reason with library items so the apps can say No audio', function () {
    [$device, $token] = $this->enroll(platform: 'android');
    $this->asToken($token);
    $silent = silentSession($device->user_id);
    CaptureSession::query()->whereKey($silent->id)->update(['device_id' => $device->id]);

    $item = collect($this->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $silent->id);

    expect($item['transcript']['final_error_code'])->toBe('no_audio')->and($item['transcript']['final_status'])->toBe('failed');
});

it('shows No audio on the recording page without a player, share button or share links', function () {
    Filament::setCurrentPanel('admin');
    $admin = $this->signInAsAdmin();
    $silent = silentSession($admin->id);

    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $silent->id]);
    $page->assertSee('No audio')->assertSee('Test call capture')->assertActionHidden('share');
    expect(ShareLinksRelationManager::canViewForRecord($silent, ViewCaptureSession::class))->toBeFalse();
});

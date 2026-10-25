<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\Security;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\WebSessions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/* The security centre (B16): the audit log, signed-in browsers, paired devices and the device API. */

function auditEvents(int $userId): array
{
    return AuditEvent::query()->where('user_id', $userId)->orderBy('id')->pluck('event')->all();
}

describe('the audit log', function () {
    it('records console sign-ins, failures and sign-outs without passwords', function () {
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct horse battery']);

        expect(Auth::guard('web')->attempt(['email' => 'owner@example.test', 'password' => 'wrong password here']))->toBeFalse();
        Auth::guard('web')->login($user);
        Auth::guard('web')->logout();

        expect(auditEvents($user->id))->toBe(['login_failed', 'login', 'logout']);
        $failed = AuditEvent::query()->where('event', 'login_failed')->firstOrFail();
        expect($failed->summary)->toBe('Sign-in failed for owner@example.test')
            ->and(json_encode(AuditEvent::query()->get()->toArray()))->not->toContain('wrong password');
    });

    it('records pairing, deletes, exports, share links and key renewal by the device that did them', function () {
        [$device, $token] = $this->enroll(platform: 'windows');
        $this->asToken($token);
        $session = CaptureSession::factory()->create(['user_id' => $device->user_id, 'title' => 'Acme kickoff']);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x',
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]]);

        $this->get("/api/transcripts/{$transcript->id}/export?format=txt", ['Authorization' => "Bearer {$token}"])->assertOk();
        $share = $this->postJson("/api/sessions/{$session->id}/shares", ['expires_in' => 3600])->assertCreated()->json('id');
        $this->deleteJson("/api/shares/{$share}")->assertNoContent();
        $this->deleteJson("/api/sessions/{$session->id}")->assertNoContent();
        $this->postJson('/api/devices/rotate-token')->assertOk();

        expect(auditEvents($device->user_id))->toBe(['device_paired', 'transcript_exported', 'share_created', 'share_revoked', 'session_deleted', 'device_token_rotated']);
        $deleted = AuditEvent::query()->where('event', 'session_deleted')->firstOrFail();
        expect($deleted->actor)->toBe('device')->and($deleted->device_id)->toBe($device->id)
            ->and($deleted->summary)->toBe('Recording deleted: Acme kickoff')->and($deleted->subject_id)->toBe($session->id);
    });

    it('records password, two-step and provider credential changes without the secrets', function () {
        $user = $this->signInAsAdmin();
        $user->update(['password' => 'a brand new password']);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationSecret(null);
        app(CredentialVault::class)->put('assemblyai', 'api_key', 'sk-very-secret-value', $user->id);

        expect(array_slice(auditEvents($user->id), -4))->toBe(['password_changed', 'mfa_enabled', 'mfa_disabled', 'credentials_changed']);
        $log = json_encode(AuditEvent::query()->get()->toArray());
        expect($log)->not->toContain('sk-very-secret-value')->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('a brand new password');
        expect(AuditEvent::query()->where('event', 'credentials_changed')->firstOrFail()->meta)->toBe(['provider' => 'assemblyai', 'key' => 'api_key']);
    });

    it('forgets entries after a year', function () {
        $user = User::factory()->create();
        AuditEvent::query()->create(['user_id' => $user->id, 'event' => 'login', 'actor' => 'web', 'summary' => 'old'])->forceFill(['created_at' => now()->subDays(366)])->save();
        AuditEvent::query()->create(['user_id' => $user->id, 'event' => 'login', 'actor' => 'web', 'summary' => 'recent']);

        Artisan::call('model:prune', ['--model' => [AuditEvent::class]]);

        expect(AuditEvent::query()->pluck('summary')->all())->toBe(['recent']);
    });
});

describe('the device security API', function () {
    beforeEach(function () {
        [$this->device, $this->token] = $this->enroll(platform: 'android');
        [$this->laptop] = $this->enroll($this->device->user, platform: 'windows');
        [$this->stranger] = $this->enroll();
        $this->asToken($this->token);
    });

    it('lists the owner\'s devices and recent events', function () {
        $answer = $this->getJson('/api/security')->assertOk();

        expect(collect($answer->json('devices'))->pluck('id')->sort()->values()->all())->toBe(collect([$this->device->id, $this->laptop->id])->sort()->values()->all())
            ->and(collect($answer->json('devices'))->firstWhere('id', $this->device->id)['current'])->toBeTrue()
            ->and(collect($answer->json('events'))->pluck('event')->unique()->values()->all())->toBe(['device_paired'])
            ->and($answer->json('events.0.label'))->toBe('Device paired');
    });

    it('revokes another device of the owner but not itself or a stranger\'s', function () {
        $this->deleteJson("/api/devices/{$this->device->id}")->assertStatus(409)->assertJsonPath('code', 'current_device');
        $this->deleteJson("/api/devices/{$this->stranger->id}")->assertNotFound()->assertJsonPath('code', 'device_not_found');
        $this->deleteJson("/api/devices/{$this->laptop->id}")->assertNoContent();

        expect(Device::query()->find($this->laptop->id))->toBeNull()->and(Device::query()->find($this->stranger->id))->not->toBeNull();
        $revoked = AuditEvent::query()->where('event', 'device_revoked')->firstOrFail();
        expect($revoked->device_id)->toBe($this->device->id)->and($revoked->summary)->toContain($this->laptop->name);
    });
});

describe('signed-in browsers', function () {
    beforeEach(function () {
        config(['session.driver' => 'database']);
        $this->user = User::factory()->create();
        $other = User::factory()->create();
        $row = fn (string $id, int $userId, string $agent, int $ago) => ['id' => $id, 'user_id' => $userId, 'ip_address' => '203.0.113.7', 'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->subMinutes($ago)->timestamp];
        DB::table('sessions')->insert([
            $row('current', $this->user->id, 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36', 1),
            $row('phone', $this->user->id, 'Mozilla/5.0 (Linux; Android 15) Chrome/130.0 Mobile Safari/537.36', 30),
            $row('laptop', $this->user->id, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Version/18.0 Safari/605.1.15', 90),
            $row('stranger', $other->id, 'Firefox/130.0', 5),
        ]);
    });

    it('lists the owner\'s browsers, newest first, and names them', function () {
        $list = app(WebSessions::class)->list($this->user->id, 'current');
        expect(array_column($list, 'browser'))->toBe(['Chrome on Windows', 'Chrome on Android', 'Safari on macOS'])
            ->and($list[0]['current'])->toBeTrue()->and($list[1]['current'])->toBeFalse();
    });

    it('signs out one browser or all others, never the current one or another owner\'s', function () {
        $sessions = app(WebSessions::class);
        expect($sessions->revoke($this->user->id, 'current', 'current'))->toBeFalse()
            ->and($sessions->revoke($this->user->id, 'stranger', 'current'))->toBeFalse()
            ->and($sessions->revoke($this->user->id, 'phone', 'current'))->toBeTrue()
            ->and($sessions->revokeOthers($this->user->id, 'current'))->toBe(1);

        expect(DB::table('sessions')->pluck('id')->sort()->values()->all())->toBe(['current', 'stranger'])
            ->and(auditEvents($this->user->id))->toBe(['web_session_revoked', 'web_session_revoked']);
    });

    it('lists nothing when sessions are not kept in the database', function () {
        config(['session.driver' => 'array']);
        expect(app(WebSessions::class)->list($this->user->id, 'current'))->toBe([]);
    });
});

describe('the console security page', function () {
    it('shows the audit log and the browsers, and signs a browser out', function () {
        config(['session.driver' => 'database']);
        $admin = $this->signInAsAdmin();
        AuditEvent::query()->create(['user_id' => $admin->id, 'event' => 'share_created', 'actor' => 'web', 'summary' => 'Share link made for Acme kickoff']);
        AuditEvent::query()->create(['user_id' => User::factory()->create()->id, 'event' => 'login', 'actor' => 'web', 'summary' => 'Someone else']);
        DB::table('sessions')->insert(['id' => 'old-browser', 'user_id' => $admin->id, 'ip_address' => '198.51.100.4', 'user_agent' => 'Firefox/130.0 (Windows)', 'payload' => '', 'last_activity' => now()->subDay()->timestamp]);

        $page = $this->panelComponent(Security::class)->assertOk()
            ->assertSee('Share link made for Acme kickoff')->assertDontSee('Someone else')
            ->assertSee('Firefox on Windows');
        $page->call('signOutBrowser', 'old-browser')->assertNotified('Browser signed out');

        expect(DB::table('sessions')->where('id', 'old-browser')->exists())->toBeFalse();
    });
});

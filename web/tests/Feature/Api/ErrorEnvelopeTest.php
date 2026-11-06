<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\WorkspaceRole;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Plan;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\PairingService;
use App\Services\Screens\ScreenStore;
use App\Services\SettingsRepository;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/../Accounts/Support/account_helpers.php';

/*
 * docs/API.md "Error codes" (since v1.1): every error answer is {"message", "code", "errors"?}, clients branch on code,
 * messages never name internal classes and a 429 always carries Retry-After. Each documented code is produced here
 * through the real endpoints.
 */

const ENVELOPE_MISSING_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

/**
 * Codes produced by later work packages; their own tests cover them until then. The v1.5 contract is written ahead
 * of the server: each phase removes its codes here when it adds their scenarios.
 */
const ENVELOPE_PENDING_CODES = [
    'budget_exceeded',
];

/** @return array<string, array{0: int, 1: string}> the docs table as code => [status, code] */
function documentedErrorCodes(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/API.md');
    // The table runs up to the next section; it outgrew a fixed window when v1.5 added its codes.
    $start = (int) strpos($doc, '### Error codes');
    $end = strpos($doc, "\n## ", $start);
    $section = substr($doc, $start, $end === false ? null : $end - $start);
    preg_match_all('/^\| (\d{3}) \| `([a-z_]+)` \|/m', $section, $m, PREG_SET_ORDER);
    $codes = [];
    foreach ($m as [, $status, $code]) {
        $codes[$code] = [(int) $status, $code];
    }

    return $codes;
}

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->bytes = Fixtures::audio(3000);
});

/** Drives the API into the error named by $code and returns the answer. */
function produceError(object $test, string $code): TestResponse
{
    switch ($code) {
        case 'unauthenticated':
            app('auth')->forgetGuards();

            return $test->flushHeaders()->getJson('/api/config');
        case 'missing_ability':
            [, $limited] = $test->enroll($test->device->user, ['library:read']);

            return $test->asToken($limited)->getJson('/api/config');
        case 'not_found':
            return $test->getJson('/api/no-such-route');
        case 'session_not_found':
            return $test->getJson('/api/sessions/'.ENVELOPE_MISSING_ID);
        case 'recording_not_found':
            return $test->getJson('/api/recordings/'.ENVELOPE_MISSING_ID);
        case 'transcript_not_found':
            return $test->getJson('/api/transcripts/'.ENVELOPE_MISSING_ID);
        case 'client_not_found':
            return $test->createSession($test->bytes, ['client_id' => ENVELOPE_MISSING_ID]);
        case 'method_not_allowed':
            return $test->deleteJson('/api/config');
        case 'upload_complete':
            $flow = $test->uploadRecording($test->bytes);

            return $test->postChunk($flow['recording'], 0, $test->bytes);
        case 'session_not_recording':
            $session = CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'status' => SessionStatus::Uploading]);

            return $test->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $session->id]);
        case 'too_many_open_uploads':
            foreach (range(1, ChunkedUploadService::MAX_OPEN_UPLOADS) as $_) {
                $session = CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'status' => SessionStatus::Uploading]);
                app(ChunkedUploadService::class)->createRecording($session, Fixtures::artifact($test->bytes), 1024 * 1024);
            }

            return $test->createSession($test->bytes);
        case 'too_many_open_sessions':
            foreach (range(1, 10) as $i) {
                $test->openSession(['client_ref' => "open-{$i}"]);
            }

            return $test->postJson('/api/sessions', Fixtures::openSessionBody());
        case 'too_large':
            $created = $test->createSession($test->bytes)->assertCreated();

            return $test->postChunk((string) $created->json('recordings.0.id'), 0, $test->bytes.'extra bytes');
        case 'validation_failed':
            return $test->postJson('/api/sessions', []);
        case 'session_required':
            return $test->postJson('/api/stt/token', ['use_case' => 'live_captions']);
        case 'upload_incomplete':
            $created = $test->createSession($test->bytes)->assertCreated();

            return $test->postJson('/api/recordings/'.$created->json('recordings.0.id').'/complete');
        case 'verification_failed':
            return $test->uploadRecording($test->bytes, [], ['sha256' => str_repeat('0', 64)])['complete'];
        case 'restore_expired':
            $gone = CaptureSession::factory()->create(['user_id' => $test->device->user_id]);
            $gone->delete();
            CaptureSession::onlyTrashed()->whereKey($gone->id)->update(['deleted_at' => now()->subHours(49)]);

            return $test->postJson("/api/sessions/{$gone->id}/restore");
        case 'device_not_found':
            return $test->deleteJson('/api/devices/'.ENVELOPE_MISSING_ID);
        case 'current_device':
            return $test->deleteJson('/api/devices/'.$test->device->id);
        case 'ai_budget_reached':
            app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
            app(SettingsRepository::class)->set('client_ai.monthly_usd', 0);

            return $test->postJson('/api/clients/'.Client::factory()->create(['user_id' => $test->device->user_id])->id.'/ask', ['question' => 'When?']);
        case 'ai_unavailable':
            return $test->postJson('/api/clients/'.Client::factory()->create(['user_id' => $test->device->user_id])->id.'/ask', ['question' => 'When?']);
        case 'notes_not_found':
            return $test->getJson('/api/sessions/'.$test->makeReadySession($test->device, $test->bytes)->id.'/notes');
        case 'no_client':
            return $test->postJson('/api/sessions/'.$test->makeReadySession($test->device, $test->bytes)->id.'/notes');
        case 'memory_not_found':
            return $test->deleteJson('/api/clients/'.Client::factory()->create(['user_id' => $test->device->user_id])->id.'/memory/'.ENVELOPE_MISSING_ID);
        case 'file_not_found':
            return $test->deleteJson('/api/clients/'.Client::factory()->create(['user_id' => $test->device->user_id])->id.'/files/'.ENVELOPE_MISSING_ID);
        case 'suggestion_not_found':
            $transcript = $test->makeReadySession($test->device, $test->bytes)->transcripts()->create(['kind' => 'final', 'provider' => 'groq', 'status' => 'completed',
                'text' => 'x', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'x']]]);

            return $test->postJson("/api/transcripts/{$transcript->id}/suggestions/0", ['accept' => true]);
        case 'duplicate_recording':
            $test->createSession($test->bytes)->assertCreated();

            return $test->createSession($test->bytes, ['skip_duplicate' => true]);
        case 'too_many_screens':
            $session = $test->makeReadySession($test->device, $test->bytes, ['status' => SessionStatus::Completed]);
            $now = now();
            foreach (array_chunk(range(1, ScreenStore::MAX_PER_RECORDING), 200) as $chunk) {
                DB::table('screens')->insert(array_map(fn (int $i): array => ['id' => (string) Str::ulid(), 'workspace_id' => $session->workspace_id,
                    'capture_session_id' => $session->id, 'at_ms' => $i, 'width' => 1, 'height' => 1, 'trigger' => 'change', 'sha256' => hash('sha256', (string) $i),
                    'mime' => 'image/png', 'bytes' => 1, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

            return $test->post("/api/sessions/{$session->id}/screens", ['image' => UploadedFile::fake()->createWithContent('screen.png', $png), 'at_ms' => 0,
                'width' => 1, 'height' => 1, 'trigger' => 'manual', 'sha256' => hash('sha256', $png)], ['Accept' => 'application/json']);
        case 'rule_not_found':
            return $test->deleteJson('/api/clients/'.Client::factory()->create(['user_id' => $test->device->user_id])->id.'/rules/999');
        case 'rule_exists':
            $test->postJson('/api/clients/'.Client::factory()->create(['user_id' => $test->device->user_id])->id.'/rules', ['kind' => 'app', 'value' => 'zoom'])->assertCreated();

            return $test->postJson('/api/clients/'.Client::factory()->create(['user_id' => $test->device->user_id])->id.'/rules', ['kind' => 'app', 'value' => 'zoom']);
        case 'recipient_not_found':
            return $test->postJson('/api/sessions/'.$test->makeReadySession($test->device, $test->bytes)->id.'/send', ['to' => ['nobody@example.com']]);
        case 'invalid_parent':
            $project = Client::factory()->create(['user_id' => $test->device->user_id]);

            return $test->patchJson("/api/clients/{$project->id}", ['parent_id' => $project->id]);
        case 'too_deep':
            $parent = null;
            foreach (range(1, 4) as $level) {
                $parent = Client::factory()->create(['user_id' => $test->device->user_id, 'parent_id' => $parent?->id]);
            }

            return $test->postJson('/api/clients', ['name' => 'Fifth', 'parent_id' => $parent?->id]);
        case 'nothing_to_export':
            return $test->postJson('/api/sessions/export', ['ids' => [ENVELOPE_MISSING_ID], 'format' => 'txt']);
        case 'views_unavailable':
            $session = CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'status' => SessionStatus::Completed]);
            $transcript = $session->transcripts()->create(['kind' => 'final', 'provider' => 'assemblyai', 'status' => 'completed', 'language' => 'en', 'text' => 'Hello', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]]);

            // No Anthropic key is set.
            return $test->postJson("/api/transcripts/{$transcript->id}/views");
        case 'cannot_retranscribe':
            // No audio on the server for this recording.
            $session = CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'status' => SessionStatus::Completed]);

            return $test->postJson("/api/sessions/{$session->id}/retranscribe", ['language' => 'ur']);
        case 'storage_limit_reached':
            Plan::personal()->update(['storage_bytes' => 1]);

            return $test->createSession($test->bytes);
        case 'device_limit_reached':
            app('auth')->forgetGuards();
            $code = app(PairingService::class)->createCode($test->device->user);
            Plan::personal()->update(['max_devices' => 1]);

            return $test->flushHeaders()->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Second', 'platform' => 'android']);
        case 'not_allowed':
            // Since v1.5 someone else's recording is a 404; a viewer's device is what `not_allowed` is left for.
            $viewer = withoutWorkspace(User::factory()->create());
            app(Workspaces::class)->addMember(Workspace::query()->findOrFail($test->device->workspace_id), $viewer, WorkspaceRole::Viewer);
            [, $viewerToken] = $test->enroll($viewer->fresh());

            return $test->asToken($viewerToken)->createSession($test->bytes);
        case 'feature_not_in_plan':
            Plan::personal()->update(['features' => ['screen_moments' => false]]);
            $session = CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'device_id' => $test->device->id]);

            return $test->postJson("/api/sessions/{$session->id}/screens", ['at_ms' => 0, 'width' => 1, 'height' => 1, 'trigger' => 'manual']);
        case 'seat_limit_reached':
            // Only the console and the invitation page invite (no device route does): the plan's own refusal, answered
            // by the API's error handler as a device would get it.
            Plan::personal()->update(['seats' => 1]);
            $workspaceId = (int) $test->device->workspace_id;
            Route::middleware('api')->post('api/__test-seat', function () use ($workspaceId) {
                app(PlanLimits::class)->assertSeat($workspaceId);

                return response()->noContent();
            });

            return $test->postJson('/api/__test-seat');
        case 'too_many_labels':
            $session = CaptureSession::factory()->create(['user_id' => $test->device->user_id]);

            return $test->patchJson("/api/sessions/{$session->id}", ['labels' => array_map(fn (int $i): string => "label {$i}", range(1, 21))]);
        case 'invalid_cursor':
            return $test->getJson('/api/library?cursor=not-a-cursor');
        case 'rate_limited':
            foreach (range(1, 30) as $_) {
                $test->postJson('/api/stt/token', ['use_case' => 'bogus']);
            }

            return $test->postJson('/api/stt/token', ['use_case' => 'bogus']);
        case 'provider_error':
            $test->configureAssemblyAi();
            Http::fake(['streaming.assemblyai.com/*' => Http::response(['error' => 'Invalid API key'], 401)]);

            return $test->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $test->openSession()]);
        case 'otp_required':
        case 'invalid_otp':
            $person = User::factory()->create(['email' => 'two.step@example.test']);
            accountsEnrolTotp($person);
            app('auth')->forgetGuards();

            return $test->flushHeaders()->postJson('/api/auth/password', ['email' => $person->email, 'password' => 'password', 'otp' => $code === 'invalid_otp' ? '000000' : null, 'name' => 'Phone', 'platform' => 'android']);
        case 'email_not_verified':
        case 'invalid_credentials':
            $person = User::factory()->unverified()->create(['email' => 'unconfirmed@example.test']);
            app('auth')->forgetGuards();

            return $test->flushHeaders()->postJson('/api/auth/password', ['email' => $person->email, 'password' => $code === 'invalid_credentials' ? 'wrong-password-1' : 'password', 'name' => 'Phone', 'platform' => 'android']);
        case 'too_many_attempts':
            app('auth')->forgetGuards();
            $test->flushHeaders();
            foreach (range(1, 5) as $i) {
                $test->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])->postJson('/api/auth/password', ['email' => 'locked@example.test', 'password' => 'wrong-password-1', 'name' => 'Phone', 'platform' => 'android']);
            }

            return $test->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->postJson('/api/auth/password', ['email' => 'locked@example.test', 'password' => 'wrong-password-1', 'name' => 'Phone', 'platform' => 'android']);
        case 'owner_of_team':
            $teammate = withoutWorkspace(User::factory()->create());
            app(Workspaces::class)->addMember(Workspace::query()->findOrFail($test->device->workspace_id), $teammate);

            return $test->deleteJson('/api/account', ['password' => 'password']);
        case 'invalid_code':
            app('auth')->forgetGuards();

            return $test->flushHeaders()->postJson('/api/auth/device/token', ['code' => 'not-a-code', 'code_verifier' => str_repeat('v', 43), 'name' => 'Laptop', 'platform' => 'windows']);
        case 'provider_not_configured':
            return $test->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $test->openSession()]);
        default:
            throw new LogicException("No scenario for {$code}.");
    }
}

/** Nothing internal may appear in an error body. */
function expectNoInternals(TestResponse $response): void
{
    foreach (['App\\', 'Illuminate', 'Symfony', 'Exception', 'vendor', '.php', 'No query results'] as $needle) {
        expect($response->getContent())->not->toContain($needle);
    }
}

it('produces each documented code with its status, a message and nothing internal', function (int $status, string $code) {
    $response = produceError($this, $code);

    $response->assertStatus($status)->assertJsonPath('code', $code);
    expect($response->json('message'))->toBeString()->not->toBe('');
    // Since v1.5 rule_exists names the project that has the rule, recipient_not_found the emails with no account and
    // feature_not_in_plan the feature.
    expect(array_diff(array_keys($response->json()), ['message', 'code', 'errors', 'missing_chunks', 'session_id', 'client_id', 'client_name', 'emails', 'feature']))->toBe([]);
    expectNoInternals($response);
})->with(array_diff_key(documentedErrorCodes(), array_flip(ENVELOPE_PENDING_CODES)));

it('reads every row of the documented table', function () {
    expect(array_keys(documentedErrorCodes()))->toContain('unauthenticated', 'rate_limited', 'session_required', ...ENVELOPE_PENDING_CODES)
        ->and(count(documentedErrorCodes()))->toBe(55);
});

it('answers 403 budget_exceeded when the monthly limit is reached', function () {
    app(SettingsRepository::class)->set('budget.monthly_minutes', 1);
    UsageLedgerEntry::query()->create([
        'user_id' => $this->device->user_id, 'source' => 'device', 'report_id' => 'seed-limit', 'use_case' => 'dictation',
        'provider' => 'assemblyai', 'duration_ms' => 120_000, 'channels' => 1, 'billed_ms' => 120_000,
        'unit_price_usd' => 0.45, 'cost_usd' => 0.015, 'billable' => true, 'occurred_at' => now(),
    ]);

    $this->postJson('/api/stt/token', ['use_case' => 'dictation'])->assertForbidden()->assertExactJson([
        'message' => 'Monthly transcription limit reached', 'code' => 'budget_exceeded',
    ]);
});

it('answers 422 verification_failed when the assembled file does not verify', function () {
    $flow = $this->uploadRecording($this->bytes, [], ['sha256' => str_repeat('0', 64)]);

    $flow['complete']->assertStatus(422)->assertExactJson([
        'message' => 'The uploaded file did not match its checksum. Send it again.', 'code' => 'verification_failed',
    ]);
});

it('lists field errors only on validation failures', function () {
    $response = $this->postJson('/api/sessions', ['client_ref' => '', 'source_app' => 'fax'])->assertStatus(422);

    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKeys(['client_ref', 'source_app', 'started_at']);
    expect($this->getJson('/api/sessions/'.ENVELOPE_MISSING_ID)->json())->not->toHaveKey('errors');
});

it('keeps the missing chunks next to the code when an upload is incomplete', function () {
    $created = $this->createSession($this->bytes)->assertCreated();

    $this->postJson('/api/recordings/'.$created->json('recordings.0.id').'/complete')
        ->assertStatus(422)
        ->assertExactJson(['message' => 'Upload is incomplete.', 'code' => 'upload_incomplete', 'missing_chunks' => [0]]);
});

it('answers a missing chunk index header with validation_failed', function () {
    $created = $this->createSession($this->bytes)->assertCreated();

    $this->postChunk((string) $created->json('recordings.0.id'), null, $this->bytes)
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['index']);
});

it('gives the same generic 404 for every model, never the model class', function (string $uri, string $code, string $message) {
    $response = $this->getJson(str_replace('{id}', ENVELOPE_MISSING_ID, $uri))->assertNotFound();

    $response->assertExactJson(['message' => $message, 'code' => $code]);
})->with([
    'session' => ['/api/sessions/{id}', 'session_not_found', 'Session not found.'],
    'recording' => ['/api/recordings/{id}', 'recording_not_found', 'Recording not found.'],
    'transcript' => ['/api/transcripts/{id}', 'transcript_not_found', 'Transcript not found.'],
    'route' => ['/api/nothing/{id}', 'not_found', 'Not found.'],
]);

it('always sends Retry-After with a 429, on every limiter', function () {
    $sttBlocked = produceError($this, 'rate_limited')->assertStatus(429);
    expect((int) $sttBlocked->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($sttBlocked->headers->get('X-RateLimit-Limit'))->toBe('30')
        ->and($sttBlocked->json())->toBe(['message' => 'Too Many Attempts.', 'code' => 'rate_limited']);

    $this->flushHeaders();
    foreach (range(1, 5) as $_) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android']);
    }
    $pairingBlocked = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android']);
    $pairingBlocked->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    expect((int) $pairingBlocked->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('hides the details of an unexpected server error', function () {
    Route::middleware('api')->get('api/__test-failure', fn () => throw new RuntimeException('database password in App\\Models\\Secret'));

    $response = $this->getJson('/api/__test-failure')->assertStatus(500);

    expect($response->json())->toBe(['message' => 'Server Error', 'code' => 'server_error']);
    expectNoInternals($response);
});

it('leaves console pages to the console', function () {
    $response = $this->flushHeaders()->get('/admin/no-such-page');

    expect($response->getStatusCode())->toBeIn([302, 404])
        ->and((string) $response->getContent())->not->toContain('"code"');
});

<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\UsageLedgerEntry;
use App\Services\ChunkedUploadService;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Error codes" (since v1.1): every error answer is {"message", "code", "errors"?}, clients branch on code,
 * messages never name internal classes and a 429 always carries Retry-After. Each documented code is produced here
 * through the real endpoints.
 */

const ENVELOPE_MISSING_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

/** Codes produced by later work packages; their own tests cover them until then. */
const ENVELOPE_PENDING_CODES = ['budget_exceeded'];

/** @return array<string, array{0: int, 1: string}> the docs table as code => [status, code] */
function documentedErrorCodes(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/API.md');
    $section = substr($doc, (int) strpos($doc, '### Error codes'), 6000);
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
        case 'nothing_to_export':
            return $test->postJson('/api/sessions/export', ['ids' => [ENVELOPE_MISSING_ID], 'format' => 'txt']);
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
    expect(array_diff(array_keys($response->json()), ['message', 'code', 'errors', 'missing_chunks']))->toBe([]);
    expectNoInternals($response);
})->with(array_diff_key(documentedErrorCodes(), array_flip(ENVELOPE_PENDING_CODES)));

it('reads every row of the documented table', function () {
    expect(array_keys(documentedErrorCodes()))->toContain('unauthenticated', 'rate_limited', 'session_required', ...ENVELOPE_PENDING_CODES)
        ->and(count(documentedErrorCodes()))->toBe(27);
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

<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientKeyterm;
use App\Models\Device;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Stt\Drivers\AssemblyAiDriver;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/** Pair a new device through the public registration endpoint, as the apps do. */
function pairDevice(object $test, User $admin, string $platform, string $name): array
{
    $code = app(PairingService::class)->createCode($admin);
    $test->flushHeaders();
    $response = $test->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => $name, 'platform' => $platform, 'app_version' => '1.0.0', 'os_version' => '16'])->assertCreated();

    return [$response->json('device.id'), $response->json('token'), $response];
}

describe('a phone call transcribed by AssemblyAI', function () {
    it('goes from pairing to playback to deletion', function () {
        $admin = User::factory()->create();
        $this->configureAssemblyAi();
        config(['app.url' => 'https://ledger.example.com']);
        $calls = [];
        $this->fakeAssemblyAi('job_42', null, $calls);

        // 1. Pair a phone and a laptop.
        [$phoneId, $phone, $registered] = pairDevice($this, $admin, 'android', 'Pixel');
        [$laptopId, $laptop] = pairDevice($this, $admin, 'windows', 'Work laptop');
        expect($registered->json('device.source_device'))->toBe('phone');

        // 2. The phone prepares: configuration and the client list.
        $this->asToken($phone)->getJson('/api/config')->assertOk()->assertJsonPath('providers.final_transcript.provider', 'assemblyai');
        $client = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings', 'language' => 'en']);
        ClientKeyterm::query()->create(['client_id' => $client->id, 'term' => 'Farah Khan']);
        expect($this->asToken($phone)->getJson('/api/clients')->json('data.0.keyterm_count'))->toBe(1);

        // 3. A call starts with captions on: the phone opens the session, then asks for a streaming token that
        //    carries the client's vocabulary.
        $opened = $this->postJson('/api/sessions', Fixtures::openSessionBody(['client_id' => $client->id, 'source_app' => 'whatsapp', 'client_ref' => 'call-001']))->assertCreated();
        $sessionId = $opened->json('session.id');
        expect($opened->json('session.status'))->toBe('recording')->and($opened->json('recordings'))->toBe([]);
        $grants = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $sessionId, 'channels' => ['me', 'client'], 'client_id' => $client->id, 'sample_rate' => 16000])->assertOk();
        expect($grants->json('grants'))->toHaveCount(2)->and($grants->json('keyterms'))->toBe(['Farah Khan']);

        // 4. The call ends: the phone finishes the session and uploads the recording out of order.
        $bytes = Fixtures::audio(11_000_000, 'LIFECYCLE-AUDIO');
        $created = $this->postJson("/api/sessions/{$sessionId}/finish", Fixtures::finishBody($bytes))->assertOk();
        expect($created->json('session.id'))->toBe($sessionId)->and($created->json('session.status'))->toBe('uploading');
        $recordingId = $created->json('recordings.0.id');
        $chunkSize = $created->json('recordings.0.upload.chunk_size_bytes');
        expect($created->json('recordings.0.upload.total_chunks'))->toBeGreaterThanOrEqual(3);
        $this->uploadAll($recordingId, $bytes, $chunkSize, [2, 0, 1]);
        expect($this->getJson("/api/recordings/{$recordingId}")->json('upload.received_chunks'))->toBe([0, 1, 2]);
        $this->postJson("/api/recordings/{$recordingId}/complete")->assertStatus(202);

        // 5. The server assembled, encrypted and submitted the audio for transcription.
        $recording = Recording::query()->find($recordingId);
        $transcript = Transcript::query()->where('capture_session_id', $sessionId)->where('kind', 'final')->sole();
        expect($recording->isReady())->toBeTrue()
            ->and(file_get_contents(Storage::disk('recordings')->path($recording->stored_path)))->not->toContain('LIFECYCLE-AUDIO')
            ->and($transcript->provider_job_id)->toBe('job_42')
            ->and($calls)->toBe(['GET /v3/token', 'POST /v2/upload', 'POST /v2/transcript']);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/transcript') && $r['webhook_url'] === "https://ledger.example.com/api/webhooks/stt/assemblyai?rid={$transcript->id}"
            && $r['multichannel'] === true && $r['keyterms_prompt'] === ['Farah Khan'] && $r['language_code'] === 'en');

        // 6. The phone posts the captions it collected; the library shows a session being processed.
        $this->postJson("/api/sessions/{$sessionId}/transcripts", ['kind' => 'live', 'provider' => 'assemblyai', 'language' => 'en', 'segments' => [
            Fixtures::segment(['channel' => 2, 'speaker' => 'Client', 'start_ms' => 500, 'end_ms' => 2500, 'text' => 'Hello, this is Farah.', 'words' => []]),
        ]])->assertCreated();
        $item = collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $sessionId);
        expect($item['status'])->toBe('processing')
            ->and($item['source_device'])->toBe('phone')
            ->and($item['client']['name'])->toBe('Acme Holdings')
            ->and($item['transcript'])->toMatchArray(['final_status' => 'submitted', 'live_available' => true])
            ->and($item['transcript']['preview'])->toBe('Client: Hello, this is Farah.');

        // 7. AssemblyAI calls back; the transcript is stored and the provider copy is erased.
        $this->flushHeaders()->postJson(
            "/api/webhooks/stt/assemblyai?rid={$transcript->id}",
            ['transcript_id' => 'job_42', 'status' => 'completed'],
            [AssemblyAiDriver::WEBHOOK_HEADER => AssemblyAiDriver::webhookToken('whsec_test_secret', $transcript->id)],
        )->assertOk();
        expect(array_values(array_filter($calls, fn (string $c) => str_contains($c, '/v2/'))))->toBe(['POST /v2/upload', 'POST /v2/transcript', 'GET /v2/transcript/job_42', 'DELETE /v2/transcript/job_42']);

        // 8. The laptop (a different device of the same owner) sees the finished call with the final transcript.
        $library = $this->asToken($laptop)->getJson('/api/library')->assertOk();
        $item = collect($library->json('data'))->firstWhere('id', $sessionId);
        expect($item['status'])->toBe('completed')
            ->and($item['source_device_label'])->toBe('Phone')
            ->and($item['device']['name'])->toBe('Pixel')
            ->and($item['transcript']['final_status'])->toBe('completed')
            ->and($item['transcript']['preview'])->toBe("Me: Hello there.\nClient: Hi, thanks for calling.");
        $transcriptBody = $this->getJson('/api/transcripts/'.$item['transcript']['final_transcript_id'])->assertOk();
        expect($transcriptBody->json('segments'))->toHaveCount(2)->and($transcriptBody->json('segments.1.speaker'))->toBe('Client');

        // 9. The laptop plays the recording back: the URL needs no token and honours ranges.
        $detail = $this->getJson("/api/sessions/{$sessionId}")->assertOk();
        expect($detail->json('recordings.0.status'))->toBe('ready')->and($detail->json('transcripts'))->toHaveCount(2);
        $url = $detail->json('playback_url');
        $this->flushHeaders();
        $partial = $this->withHeaders(['Range' => 'bytes=5242870-5242889'])->get($url);
        $partial->assertStatus(206);
        expect($partial->streamedContent())->toBe(substr($bytes, 5242870, 20));

        // 10. The laptop deletes the call; the phone learns about it on its next sync; the undo bin then erases it all.
        $syncPoint = $this->asToken($phone)->getJson('/api/library')->json('server_time');
        $this->travel(2)->seconds();
        $this->asToken($laptop)->deleteJson("/api/sessions/{$sessionId}")->assertNoContent();
        $this->travel(2)->seconds();
        $tombstones = $this->asToken($phone)->getJson('/api/library?since='.urlencode($syncPoint))->assertOk()->json('data');
        expect($tombstones)->toHaveCount(1)->and($tombstones[0]['id'])->toBe($sessionId)->and($tombstones[0]['deleted'])->toBeTrue();
        emptyUndoBin();
        expect(Recording::query()->count())->toBe(0)->and(Transcript::query()->count())->toBe(0)->and(Storage::disk('recordings')->allFiles())->toBe([]);

        // 11. The phone's token is rotated and the old one stops working at once.
        $rotated = $this->asToken($phone)->postJson('/api/devices/rotate-token')->assertOk()->json('token');
        $this->asToken($phone)->getJson('/api/config')->assertUnauthorized();
        $this->asToken($rotated)->getJson('/api/config')->assertOk()->assertJsonPath('device.id', $phoneId);

        // 12. The admin revokes the laptop: it is locked out, the phone is not.
        Device::query()->find($laptopId)->delete();
        $this->asToken($laptop)->getJson('/api/library')->assertUnauthorized();
        $this->asToken($rotated)->getJson('/api/library')->assertOk();
    });
});

describe('a desktop call transcribed synchronously', function () {
    it('is completed by the time the upload is finished', function () {
        $admin = User::factory()->create();
        app(SettingsRepository::class)->setMany([
            'provider.final_transcript' => 'azure_mai', 'azure.model' => 'standard', 'azure.endpoint' => 'https://speech.example.cognitiveservices.azure.com',
        ]);
        app(CredentialVault::class)->put('azure', 'api_key', 'azure_test_key_1234567890');
        Http::fake(['speech.example.cognitiveservices.azure.com/*' => Http::response(['phrases' => [
            ['channel' => 0, 'offsetMilliseconds' => 0, 'durationMilliseconds' => 1200, 'text' => 'Can you hear me?', 'words' => []],
            ['channel' => 1, 'offsetMilliseconds' => 1500, 'durationMilliseconds' => 1000, 'text' => 'Loud and clear.', 'words' => []],
        ]], 200)]);
        [, $desktop] = pairDevice($this, $admin, 'windows', 'Office PC');
        $this->asToken($desktop);

        $config = $this->getJson('/api/config')->json('providers.final_transcript');
        expect($config['mode'])->toBe('sync')->and($config['required_artifacts'])->toBe(['primary']);
        $flow = $this->uploadRecording(Fixtures::audio(300_000, 'FLAC-ISH'), ['source_app' => 'zoom', 'client_ref' => 'zoom-1'], ['mime' => 'audio/flac', 'codec' => 'flac', 'sample_rate' => 48000]);

        $flow['complete']->assertStatus(202);
        $item = $this->getJson('/api/library')->json('data.0');
        expect($item['status'])->toBe('completed')
            ->and($item['source_device'])->toBe('desktop')
            ->and($item['source_app_label'])->toBe('Zoom')
            ->and($item['transcript']['final_status'])->toBe('completed')
            ->and($item['transcript']['preview'])->toBe("Me: Can you hear me?\nClient: Loud and clear.");
    });
});

describe('a voice note transcribed on the device', function () {
    it('waits for the device and completes with its transcript', function () {
        $admin = User::factory()->create();
        app(SettingsRepository::class)->set('provider.voice_note', 'parakeet_local');
        [, $phone] = pairDevice($this, $admin, 'android', 'Pixel');
        $this->asToken($phone);
        Http::fake();

        $flow = $this->uploadRecording(Fixtures::audio(8000), ['source_app' => 'voice_note', 'client_ref' => 'note-1'], ['channels' => 1, 'sample_rate' => 0]);

        $item = $this->getJson('/api/library')->json('data.0');
        expect($item['status'])->toBe('processing')->and($item['transcript']['final_status'])->toBe('awaiting_device');

        $this->postJson("/api/sessions/{$flow['session']}/transcripts", ['kind' => 'final', 'provider' => 'parakeet_local', 'language' => 'en', 'segments' => [
            ['channel' => 1, 'start_ms' => 0, 'end_ms' => 2500, 'text' => 'Remember to send the invoice.'],
        ]])->assertCreated()->assertJsonPath('status', 'completed');

        $item = $this->getJson('/api/library')->json('data.0');
        expect($item['status'])->toBe('completed')
            ->and($item['transcript']['final_status'])->toBe('completed')
            ->and($item['transcript']['preview'])->toBe('Speaker: Remember to send the invoice.');
        expect(CaptureSession::query()->find($flow['session'])->status)->toBe(SessionStatus::Completed);
        Http::assertNothingSent();
    });
});

describe('an interrupted upload', function () {
    it('resumes where it stopped and completes once', function () {
        $admin = User::factory()->create();
        $this->configureAssemblyAi();
        $this->fakeAssemblyAi('job_resume');
        [, $phone] = pairDevice($this, $admin, 'android', 'Pixel');
        $this->asToken($phone);
        $bytes = Fixtures::audio(11_000_000, 'RESUME-AUDIO');

        // First attempt: one good chunk, one corrupted in transit, then the app is killed.
        $first = $this->createSession($bytes, ['client_ref' => 'resume-1'])->assertCreated();
        $recording = $first->json('recordings.0.id');
        $chunkSize = $first->json('recordings.0.upload.chunk_size_bytes');
        $chunks = Fixtures::chunks($bytes, $chunkSize);
        $this->postChunk($recording, 0, $chunks[0])->assertOk();
        $this->postChunk($recording, 1, substr($chunks[1], 0, -1).'!', hash('sha256', $chunks[1]))->assertStatus(422);

        // Second attempt: the app repeats the creation call, learns what the server has, and sends only the rest.
        $second = $this->createSession($bytes, ['client_ref' => 'resume-1'])->assertOk();
        expect($second->json('recordings.0.id'))->toBe($recording)->and($second->json('recordings.0.upload.received_chunks'))->toBe([0]);
        foreach (array_diff(array_keys($chunks), $second->json('recordings.0.upload.received_chunks')) as $index) {
            $this->postChunk($recording, $index, $chunks[$index])->assertOk();
        }
        $this->postJson("/api/recordings/{$recording}/complete")->assertStatus(202);
        $this->postJson("/api/recordings/{$recording}/complete")->assertStatus(202);

        expect(Recording::query()->find($recording)->isReady())->toBeTrue()
            ->and(CaptureSession::query()->count())->toBe(1)
            ->and(Transcript::query()->where('kind', 'final')->count())->toBe(1);
    });
});

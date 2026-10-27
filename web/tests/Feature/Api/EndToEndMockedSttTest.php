<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\ShareLinkView;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\PairingService;
use App\Stt\Drivers\AssemblyAiDriver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * The whole v1.1 path with the speech-to-text provider mocked (Http::fake), as one story per outcome:
 *   kept:      pair a device, start a session, get a streaming token, set a bookmark, stop, Keep, chunked upload,
 *              webhook, final transcript, search, export, share link, retention.
 *   discarded: the same start, then Discard: no upload, no transcript request, no final minutes billed.
 */

/** Registers a device through the public endpoint with a fresh pairing code, as the apps do. */
function pairedToken(object $test, User $owner, string $platform = 'windows'): string
{
    $code = app(PairingService::class)->createCode($owner);
    $test->flushHeaders();

    return $test->postJson('/api/devices/register', [
        'pairing_code' => $code, 'name' => 'Office PC', 'platform' => $platform, 'app_version' => '1.1.0', 'os_version' => '11',
    ])->assertCreated()->json('token');
}

/** Provider calls made so far that cost money: everything except minting a streaming token. */
function billedProviderCalls(array $calls): array
{
    return array_values(array_filter($calls, fn (string $c) => ! str_contains($c, '/v3/token')));
}

describe('a recording the user keeps', function () {
    it('goes from pairing to retention', function () {
        $owner = User::factory()->create();
        $this->configureAssemblyAi();
        $calls = [];
        $this->fakeAssemblyAi('job_e2e', null, $calls);

        // 1. Pair the device and open a session: still nothing sent to the provider.
        $token = pairedToken($this, $owner);
        $this->asToken($token)->getJson('/api/config')->assertOk();
        $sessionId = $this->postJson('/api/sessions', Fixtures::openSessionBody(['source_app' => 'zoom', 'client_ref' => 'e2e-1']))
            ->assertCreated()->json('session.id');

        // 2. Live captions: the server mints short-lived stream tokens, the provider key stays on the server.
        $grants = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $sessionId, 'channels' => ['me', 'client'], 'sample_rate' => 16000])
            ->assertOk()->json('grants');
        expect($grants)->toHaveCount(2)->and(json_encode($grants))->not->toContain('assemblyai_test_key');

        // 3. A bookmark during the call.
        $this->postJson("/api/sessions/{$sessionId}/markers", ['at_ms' => 4_000, 'tag' => 'Budget', 'note' => 'agreed 5k'])->assertCreated();

        // 4. Stop, then Keep: only now is the audio uploaded, in chunks.
        expect(billedProviderCalls($calls))->toBe([]);
        $bytes = Fixtures::audio(11_000_000, 'E2E-AUDIO');
        $finished = $this->postJson("/api/sessions/{$sessionId}/finish", Fixtures::finishBody($bytes))->assertOk();
        $recordingId = $finished->json('recordings.0.id');
        $this->uploadAll($recordingId, $bytes, $finished->json('recordings.0.upload.chunk_size_bytes'), [1, 0, 2]);
        $this->postJson("/api/recordings/{$recordingId}/complete")->assertStatus(202);
        expect(billedProviderCalls($calls))->toBe(['POST /v2/upload', 'POST /v2/transcript']);

        // 5. The provider's webhook delivers the final transcript, and the provider copy is erased.
        $transcript = unscoped(fn () => Transcript::query()->where('capture_session_id', $sessionId)->where('kind', 'final')->sole());
        $this->flushHeaders()->postJson(
            "/api/webhooks/stt/assemblyai?rid={$transcript->id}",
            ['transcript_id' => 'job_e2e', 'status' => 'completed'],
            [AssemblyAiDriver::WEBHOOK_HEADER => AssemblyAiDriver::webhookToken('whsec_test_secret', $transcript->id)],
        )->assertOk();
        expect($calls)->toContain('DELETE /v2/transcript/job_e2e');
        $this->asToken($token);
        $item = collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $sessionId);
        expect($item['status'])->toBe('completed')->and($item['transcript']['final_status'])->toBe('completed');
        $transcriptId = $item['transcript']['final_transcript_id'];

        // 6. The device reports the minutes it streamed; the server adds the final minutes.
        $this->postJson('/api/usage', ['report_id' => 'e2e-usage-1', 'use_case' => 'live_captions', 'provider' => 'assemblyai', 'streamed_ms' => 120_000, 'channels' => 2])->assertCreated();
        expect(unscoped(fn () => UsageLedgerEntry::query()->where('source', 'device')->count()))->toBe(1);

        // 7. Search finds the moment, by words, by bookmark and by tag.
        foreach (['q=thanks+for+calling', 'q=thanks+for+calling&bookmarked=1', 'q=thanks+for+calling&tag=Budget'] as $query) {
            $found = $this->getJson("/api/search?{$query}")->assertOk()->json('data');
            expect(collect($found)->pluck('session.id')->all())->toBe([$sessionId], $query);
        }
        expect($this->getJson('/api/search?q=thanks+for+calling&tag=Deadline')->json('data'))->toBe([]);

        // 8. Export in two formats.
        $txt = $this->get("/api/transcripts/{$transcriptId}/export?format=txt", ['Authorization' => 'Bearer '.$token])->assertOk()->getContent();
        expect($txt)->toContain('Hi, thanks for calling.');
        $srt = $this->get("/api/transcripts/{$transcriptId}/export?format=srt", ['Authorization' => 'Bearer '.$token])->assertOk()->getContent();
        expect($srt)->toContain('-->');

        // 9. A share link opens without a token, shows the transcript, and the opening is logged.
        $share = $this->postJson("/api/sessions/{$sessionId}/shares", ['expires_in' => 3_600])->assertCreated()->json();
        $path = parse_url($share['url'], PHP_URL_PATH);
        $this->flushHeaders()->get($path)->assertOk()->assertSee('Hi, thanks for calling.');
        expect(unscoped(fn () => ShareLinkView::query()->where('outcome', 'viewed')->count()))->toBe(1);

        // 10. Retention: past the window the recording goes to the undo bin; the link stops working at once and the
        //     bin then erases the audio and the transcript.
        $old = now()->subDays(40);
        CaptureSession::withTrashed()->whereKey($sessionId)->update(['created_at' => $old, 'updated_at' => $old]);
        Recording::query()->where('capture_session_id', $sessionId)->update(['created_at' => $old]);
        Transcript::query()->where('capture_session_id', $sessionId)->update(['created_at' => $old]);
        $storedPath = unscoped(fn () => Recording::query()->where('capture_session_id', $sessionId)->value('stored_path'));

        Artisan::call('recordings:retention');

        $this->asToken($token)->getJson("/api/sessions/{$sessionId}")->assertNotFound();
        $this->flushHeaders()->get($path)->assertNotFound();
        emptyUndoBin();
        expect(unscoped(fn () => Recording::query()->where('capture_session_id', $sessionId)->count()))->toBe(0)
            ->and(unscoped(fn () => Transcript::query()->where('capture_session_id', $sessionId)->count()))->toBe(0)
            ->and(Storage::disk('recordings')->exists($storedPath))->toBeFalse();
    });
});

describe('a recording the user discards', function () {
    it('uploads nothing, asks for no transcript and bills no final minutes', function () {
        $owner = User::factory()->create();
        $this->configureAssemblyAi();
        $calls = [];
        $this->fakeAssemblyAi('job_never', null, $calls);

        $token = pairedToken($this, $owner);
        $this->asToken($token);
        $sessionId = $this->postJson('/api/sessions', Fixtures::openSessionBody(['source_app' => 'zoom', 'client_ref' => 'e2e-2']))
            ->assertCreated()->json('session.id');
        $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $sessionId, 'channels' => ['me', 'client'], 'sample_rate' => 16000])->assertOk();
        $this->postJson("/api/sessions/{$sessionId}/markers", ['at_ms' => 2_000, 'tag' => 'Budget'])->assertCreated();

        // Stop, then Discard: the device deletes the open session and never sends the audio.
        $this->deleteJson("/api/sessions/{$sessionId}")->assertNoContent();
        emptyUndoBin();

        expect(billedProviderCalls($calls))->toBe([])
            ->and(unscoped(fn () => Recording::query()->count()))->toBe(0)
            ->and(unscoped(fn () => Transcript::query()->count()))->toBe(0)
            ->and(unscoped(fn () => UsageLedgerEntry::query()->where('source', '!=', 'device')->count()))->toBe(0)
            ->and(Storage::disk('recordings')->allFiles())->toBe([]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/'));
        $this->getJson("/api/sessions/{$sessionId}")->assertNotFound();
        $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $sessionId, 'channels' => ['me', 'client'], 'sample_rate' => 16000])->assertStatus(404);
    });
});

<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Mail\TranscriptFailedMail;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Transcript;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\EngineComparison;
use App\Services\Library\ReadyNotices;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
| D-131 (v1.8.1): why a transcript failed reaches people only in plain words chosen by its error code, never the
| engine's or the provider's own text, which can name them ("Gemini could not read the audio.", "AssemblyAI returned no
| transcript id."): the Activity item (the console's bell and Activity, the apps' Activity and the system notification
| they build from it), the API's `error`, and the email. That text stays with the transcript, for the operator.
*/

/** The engine, model and vendor names an engine's own words may carry. */
const FAILURE_VENDORS = ['Gemini', 'AssemblyAI', 'Groq', 'ElevenLabs', 'OpenAI', 'Anthropic', 'Claude', 'Whisper', 'Soniox', 'Azure', 'Scribe', 'universal-2'];

/** An error text naming them all. */
const FAILURE_RAW = 'The speech provider could not transcribe this recording: Gemini could not read the audio. AssemblyAI returned no transcript id. '
    .'(Tried: AssemblyAI, Groq Whisper large-v3, ElevenLabs Scribe, Soniox, Azure Speech; OpenAI, Anthropic Claude; universal-2.)';

/** The vendor names in [$text]; with [$freeKey], the Free plan's own Groq key, the one name people act on, is let be. */
function failureVendorsIn(string $text, bool $freeKey = false): array
{
    $text = $freeKey ? str_replace('free Groq key', '', $text) : $text;

    // Whole words, in any case: "Scribe" is a name, "transcribe" is not.
    return array_values(array_filter(FAILURE_VENDORS, fn (string $name): bool => preg_match('/\b'.preg_quote($name, '/').'\b/i', $text) === 1));
}

/** A final transcript of [$device]'s person, being transcribed, in English (so a failure is final at once). */
function failingFinal(Device $device, string $provider = 'gemini'): Transcript
{
    $session = CaptureSession::factory()->create(['user_id' => $device->user_id, 'device_id' => $device->id, 'title' => 'Call with Omar',
        'language' => 'en', 'status' => SessionStatus::Processing]);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => $provider, 'status' => TranscriptStatus::Processing, 'language' => 'en']);
}

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
});

it('turns a job that failed on a provider-named error into an Activity item and an API error without the name', function () {
    Mail::fake();
    $transcript = failingFinal($this->device);
    $session = $transcript->captureSession;
    app(ReadyNotices::class)->ask($session->user, $session);

    // Laravel calls failed() when the job breaks: here on the engine's own words.
    (new TranscribeFinalJob($transcript->id))->failed(new RuntimeException('Gemini could not read the audio. AssemblyAI returned no transcript id.'));

    $transcript->refresh();
    expect($transcript->status)->toBe(TranscriptStatus::Failed)
        ->and($transcript->error_code)->toBe('interrupted')
        // Kept with the transcript, for the operator (the console's Details) and the logs.
        ->and($transcript->error)->toBe('Gemini could not read the audio. AssemblyAI returned no transcript id.');

    // The Activity item, as the console's bell and Activity and every app read it.
    $item = collect($this->asToken($this->token)->getJson('/api/activity')->assertOk()->json('data'))->firstWhere('kind', 'transcript_failed');
    expect($item['title'])->toBe('Transcription failed')
        ->and($item['body'])->toBe('Call with Omar: '.TranscriptErrorCode::plainWords(TranscriptErrorCode::Interrupted))
        ->and(failureVendorsIn($item['title'].' '.$item['body']))->toBe([]);

    // The API's error: on the transcript and in the recording's detail.
    $one = $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()
        ->assertJsonPath('error', TranscriptErrorCode::Interrupted->message())->assertJsonPath('error_code', 'interrupted');
    $detail = $this->getJson("/api/sessions/{$session->id}")->assertOk()->json('transcripts.0');
    expect($detail['error'])->toBe(TranscriptErrorCode::Interrupted->message())->and($detail['error_code'])->toBe('interrupted')
        ->and(failureVendorsIn((string) $one->json('error').' '.$detail['error']))->toBe([]);

    // The email for "Notify me when ready" names the recording, never the engine.
    Mail::assertQueued(TranscriptFailedMail::class, fn (TranscriptFailedMail $mail): bool => failureVendorsIn($mail->render()) === []
        && str_contains($mail->render(), 'Call with Omar'));
});

it('never names an engine or a vendor in the Activity item or the API error, whatever the code and the stored words', function (?string $code) {
    $transcript = failingFinal($this->device);
    // Made with the person's own key, so no other engine takes it over: the failure is told at once.
    $transcript->update(['status' => TranscriptStatus::Failed, 'error' => FAILURE_RAW, 'error_code' => $code, 'meta' => ['own_key' => true]]);
    $freeKey = $code === TranscriptErrorCode::NeedsTranscriptionKey->value;

    $item = collect($this->asToken($this->token)->getJson('/api/activity')->assertOk()->json('data'))->firstWhere('kind', 'transcript_failed');
    $api = (string) $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->json('error');

    expect($item)->not->toBeNull()
        ->and($item['body'])->toBe('Call with Omar: '.TranscriptErrorCode::plainWords(TranscriptErrorCode::tryFrom((string) $code)))
        ->and($api)->toBe(TranscriptErrorCode::publicMessage(TranscriptErrorCode::tryFrom((string) $code)))
        ->and(failureVendorsIn($item['body'], $freeKey))->toBe([])
        ->and(failureVendorsIn($api, $freeKey))->toBe([])
        ->and(str_contains($item['body'], 'could not read the audio'))->toBeFalse();
})->with([...array_map(fn (TranscriptErrorCode $c): string => $c->value, TranscriptErrorCode::cases()), 'no code' => null, 'an unknown code' => 'engine_on_fire']);

it('writes the "Transcription failed" items already sent in plain words, from the code of their transcript', function () {
    $named = failingFinal($this->device);
    $named->update(['status' => TranscriptStatus::Failed, 'error' => FAILURE_RAW, 'error_code' => 'provider_rejected']);
    $session = $named->captureSession;
    $user = $session->user;
    $user->notifications()->delete();
    // As v1.8.0 wrote them: the engine's own words after the recording's name, escaped or (older) not.
    $old = function (string $body, ?string $sessionId, bool $escaped) use ($user): string {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'type' => 'Filament\Notifications\DatabaseNotification', 'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id, 'data' => json_encode(['title' => 'Transcription failed', 'body' => $escaped ? e($body) : $body, 'format' => 'filament',
                'viewData' => ['kind' => 'transcript_failed', 'session_id' => $sessionId, ...($escaped ? ['escaped' => true] : [])]]),
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $withSession = $old('Call with Omar: '.FAILURE_RAW, $session->id, true);
    $older = $old('Call with Omar: '.FAILURE_RAW, $session->id, false);
    $gone = $old('Weekly sync: '.FAILURE_RAW, '01JZZZZZZZZZZZZZZZZZZZZZZZ', true);
    // Other kinds stay as they are.
    $readyId = (string) Str::uuid();
    DB::table('notifications')->insert(['id' => $readyId, 'type' => 'Filament\Notifications\DatabaseNotification', 'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        'data' => json_encode(['title' => 'Transcript ready', 'body' => 'Acme kickoff', 'viewData' => ['kind' => 'transcript_ready', 'session_id' => $session->id]])]);

    (require database_path('migrations/2026_12_05_000002_plain_words_in_transcription_failed_notifications.php'))->up();

    $payload = fn (string $id): array => ActivityFeed::payload(DatabaseNotification::query()->findOrFail($id));
    $plain = 'Call with Omar: '.TranscriptErrorCode::plainWords(TranscriptErrorCode::ProviderRejected);
    expect($payload($withSession)['body'])->toBe($plain)
        ->and($payload($older)['body'])->toBe($plain)
        ->and($payload($gone)['body'])->toBe(TranscriptErrorCode::plainWords(null))
        ->and($payload($readyId)['body'])->toBe('Acme kickoff');
    foreach ([$withSession, $older, $gone] as $id) {
        expect(failureVendorsIn((string) DB::table('notifications')->where('id', $id)->value('data')))->toBe([]);
    }
});

it('says why a second version or an engine run failed in plain words, and the engine\'s own words to the operator only', function () {
    $transcript = failingFinal($this->device, 'assemblyai');
    $transcript->update(['status' => TranscriptStatus::Completed, 'text' => 'Hello', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]]);
    $session = $transcript->captureSession;
    $failed = ['provider' => 'gemini', 'status' => TranscriptStatus::Failed, 'language' => 'en', 'error' => FAILURE_RAW, 'error_code' => 'provider_rejected'];
    $alternate = $session->transcripts()->create(['kind' => TranscriptKind::Alternate, ...$failed]);
    $session->transcripts()->create(['kind' => TranscriptKind::Trial, ...$failed]);
    $plain = TranscriptErrorCode::plainWords(TranscriptErrorCode::ProviderRejected);
    $pages = fn (bool $operator): string => view('filament.pages.transcript-compare', ['final' => $transcript->fresh(), 'alternate' => $alternate, 'diff' => null])->render()
        .view('filament.pages.engine-results', ['runs' => app(EngineComparison::class)->results($session), 'canSetDefault' => $operator])->render();

    $this->actingAs($session->user, 'web');
    $html = $pages(false);
    expect($html)->toContain(e($plain))->not->toContain('could not read the audio')
        // The engines' labels name them in these tools, which pick an engine by name; the failure itself never does.
        ->and(app(EngineComparison::class)->results($session)[0]['error'])->toBe($plain);

    $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
    expect($pages(true))->toContain(e($plain))->toContain('Gemini could not read the audio.');
});

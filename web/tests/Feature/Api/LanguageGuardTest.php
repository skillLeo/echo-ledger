<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Transcript;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * One language list (v1.2): stored v1.1 values are rewritten, and a finished transcript in a language the recording
 * may not use is run once more with the language forced.
 */

beforeEach(function () {
    [$this->device] = $this->enroll(platform: 'android');
});

function guardedTranscript(object $test, ?string $language): Transcript
{
    $session = CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'language' => $language]);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'elevenlabs', 'status' => TranscriptStatus::Processing, 'language' => $language, 'provider_job_id' => 'el-1']);
}

function spoken(string $text, ?string $detected): NormalizedTranscript
{
    return new NormalizedTranscript('Client: '.$text, [new Segment(2, 'Client', 0, 4000, $text)], $detected, []);
}

describe('rewriting stored v1.1 languages', function () {
    it('turns Hindi and the mixed choices into Urdu or Arabic everywhere they are stored', function () {
        $hindi = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'language' => 'hi']);
        $mixed = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'language' => 'en+ur']);
        $arabic = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'language' => 'en+ar']);
        $english = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'language' => 'en']);
        $client = Client::factory()->create(['user_id' => $this->device->user_id, 'language' => 'en+hi']);
        $transcript = $mixed->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'language' => 'hi']);
        $settings = app(SettingsRepository::class);
        $settings->set('default_language', 'en+ur');
        $settings->set('routing.final', ['hi' => ['assemblyai'], 'en+ur' => ['soniox', 'elevenlabs'], 'en' => ['assemblyai']]);

        (require database_path('migrations/2026_11_04_000001_unify_languages.php'))->up();

        $language = fn ($model) => DB::table($model->getTable())->where('id', $model->id)->value('language');
        expect($language($hindi))->toBe('ur')
            ->and($language($mixed))->toBe('ur')
            ->and($language($arabic))->toBe('ar')
            ->and($language($english))->toBe('en')
            ->and($language($client))->toBe('ur')
            ->and($language($transcript))->toBe('ur');
        $settings->flush();
        expect($settings->get('default_language'))->toBe('ur')
            ->and($settings->get('routing.final')['ur'])->toBe(['soniox', 'elevenlabs'])
            ->and($settings->get('routing.final'))->not->toHaveKey('hi')->not->toHaveKey('en+ur');
    });
});

describe('enforcing the allowed languages', function () {
    it('runs Auto again in the fallback language when the engine heard a language Auto may not pick', function () {
        Queue::fake();
        $transcript = guardedTranscript($this, null);

        app(TranscriptStore::class)->complete($transcript, spoken('Bonjour, la réunion est demain matin à dix heures.', 'fr'));

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->language)->toBe('ur')
            ->and($transcript->meta['forced_reason'])->toBe('not_allowed:fr')
            ->and($transcript->meta['first_detected_language'])->toBe('fr');
        Queue::assertPushed(TranscribeFinalJob::class, 1);

        // The forced run is kept whatever it says (never a loop).
        $transcript->update(['status' => TranscriptStatus::Processing]);
        app(TranscriptStore::class)->complete($transcript, spoken('میٹنگ کل صبح دس بجے ہے', 'ur'));
        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Completed)->and($transcript->language)->toBe('ur');
        Queue::assertPushed(TranscribeFinalJob::class, 1);
    });

    it('uses the console fallback and lets Auto pick a ticked More language', function () {
        Queue::fake();
        app(SettingsRepository::class)->set('languages.auto_fallback', 'en');
        $transcript = guardedTranscript($this, null);
        app(TranscriptStore::class)->complete($transcript, spoken('Hola, la reunión es mañana.', 'es'));
        expect($transcript->refresh()->language)->toBe('en');

        app(SettingsRepository::class)->set('languages.auto_more', ['es']);
        $kept = guardedTranscript($this, null);
        app(TranscriptStore::class)->complete($kept, spoken('Hola, la reunión es mañana.', 'es'));
        expect($kept->refresh()->status)->toBe(TranscriptStatus::Completed)->and($kept->language)->toBe('es');
    });

    it('takes Hindi as Urdu when the text is in Urdu script, and English inside Urdu as allowed', function () {
        Queue::fake();
        $auto = guardedTranscript($this, null);
        app(TranscriptStore::class)->complete($auto, spoken('آپ کل کی meeting reschedule کر دیں، میں invoice بھیج دوں گا', 'hi'));
        expect($auto->refresh()->status)->toBe(TranscriptStatus::Completed)->and($auto->language)->toBe('ur')
            ->and($auto->meta['detected_language'])->toBe('hi');

        $urdu = guardedTranscript($this, 'ur');
        app(TranscriptStore::class)->complete($urdu, spoken('Please send the invoice tomorrow morning, thank you.', 'en'));
        expect($urdu->refresh()->status)->toBe(TranscriptStatus::Completed)->and($urdu->language)->toBe('ur');
        Queue::assertNothingPushed();
    });

    it('does not second-guess an engine that names no language, and never re-runs live captions', function () {
        Queue::fake();
        $quiet = guardedTranscript($this, null);
        app(TranscriptStore::class)->complete($quiet, spoken('Hello there.', null));
        expect($quiet->refresh()->status)->toBe(TranscriptStatus::Completed);

        $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id]);
        $live = $session->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing]);
        app(TranscriptStore::class)->complete($live, spoken(str_repeat('नमस्ते आप कैसे हैं ', 5), 'hi'));
        expect($live->refresh()->status)->toBe(TranscriptStatus::Completed);
        Queue::assertNothingPushed();
    });
});

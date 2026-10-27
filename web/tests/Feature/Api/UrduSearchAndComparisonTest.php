<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\EngineComparison;
use App\Services\LibrarySearch;
use App\Services\SettingsRepository;
use App\Stt\LanguageRouter;
use App\Support\UrduText;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/*
 * Urdu spelling variants in search, and Compare engines (v1.1.2).
 */

describe('folding Urdu spelling variants', function () {
    it('makes Arabic and Urdu letter forms, vowel marks and digit forms one', function () {
        // "کیا" written with Arabic Kaf and Arabic Yeh; "بہت" with Arabic Heh; a vowel mark; Extended Arabic-Indic digits.
        expect(UrduText::fold('كيا'))->toBe('کیا')
            ->and(UrduText::fold('بهت'))->toBe('بہت')
            ->and(UrduText::fold('مُلک'))->toBe('ملک')
            ->and(UrduText::fold('أحمد'))->toBe('احمد')
            ->and(UrduText::fold('۲۰۲۶'))->toBe('2026')
            ->and(UrduText::fold('Invoice ہے'))->toBe('invoice ہے')
            ->and(UrduText::fold('नमस्ते'))->toBe('नमस्ते');
    });

    it('maps each folded character back to the original one', function () {
        [$folded, $map] = UrduText::foldWithMap('مُلک');

        expect($folded)->toBe('ملک')->and($map)->toBe([0, 2, 3]);
    });
});

describe('searching Urdu transcripts', function () {
    beforeEach(function () {
        [$this->device, $this->token] = $this->enroll(platform: 'windows');
        $this->asToken($this->token);
    });

    function urduSession(object $test, string $text): CaptureSession
    {
        $session = CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'status' => SessionStatus::Completed]);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'elevenlabs', 'status' => TranscriptStatus::Completed, 'text' => $text,
            'segments' => [['channel' => 2, 'speaker' => 'Client', 'start_ms' => 4000, 'end_ms' => 6000, 'text' => $text]]]);

        return $session;
    }

    it('finds a word however the engine spelled it, both ways', function () {
        $arabicForms = urduSession($this, 'آپ كيا كہہ رہے ہيں');
        $urduForms = urduSession($this, 'یہ بہت اچھا ہے');

        $found = fn (string $q): array => collect($this->getJson('/api/search?q='.urlencode($q))->json('data'))->pluck('session.id')->all();

        expect($found('کیا'))->toBe([$arabicForms->id])
            ->and($found('بهت'))->toBe([$urduForms->id]);
    });

    it('keeps English loanwords searchable inside Urdu speech and highlights the original word', function () {
        $session = urduSession($this, 'میں نے invoice بھیج دیا ہے');

        $hit = $this->getJson('/api/search?q=Invoice')->json('data.0');
        expect($hit['session']['id'])->toBe($session->id)
            ->and($hit['hits'][0]['start_ms'])->toBe(4000);
        [$snippet, $ranges] = LibrarySearch::snippet('مُلک invoice', ['ملک']);
        expect($ranges)->toBe([[0, 4]])->and(mb_substr($snippet, 0, 4))->toBe('مُلک');
    });

    it('fills the folded column when a transcript is saved or edited', function () {
        $session = urduSession($this, 'كيا');
        $transcript = $session->finalTranscript;
        expect($transcript->search_text)->toBe('کیا');

        $transcript->update(['text' => 'ہيں']);
        expect($transcript->fresh()->search_text)->toBe('ہیں');
    });
});

describe('compare engines', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->admin = $this->signInAsAdmin();
        foreach (['assemblyai', 'elevenlabs', 'soniox'] as $provider) {
            app(CredentialVault::class)->put($provider, 'api_key', $provider.'_key_1234567890');
        }
        $this->session = $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 2000, 'text' => 'नमस्ते आप कैसे हैं']],
            ['status' => SessionStatus::Completed, 'language' => 'ur']);
        $this->readyRecording($this->session);
    });

    it('runs up to three engines in the chosen language as comparison runs', function () {
        Queue::fake();

        $runs = app(EngineComparison::class)->start($this->session, [SttProviderKey::ElevenLabs, SttProviderKey::Soniox, SttProviderKey::AssemblyAi, SttProviderKey::AzureMai], 'ur');

        expect(array_map(fn (Transcript $t) => [$t->kind, $t->provider, $t->language], $runs))->toBe([
            [TranscriptKind::Trial, 'elevenlabs', 'ur'], [TranscriptKind::Trial, 'soniox', 'ur'], [TranscriptKind::Trial, 'assemblyai', 'ur'],
        ]);
        Queue::assertPushed(TranscribeFinalJob::class, 3);
        expect($this->session->finalTranscript->fresh()->kind)->toBe(TranscriptKind::Final);

        app(EngineComparison::class)->start($this->session, [SttProviderKey::Soniox], 'ur');
        expect($this->session->trials()->where('provider', 'soniox')->count())->toBe(1);
    });

    it('lists each run with its text and cost, and the winner becomes the first engine for the language', function () {
        $run = Transcript::query()->create(['capture_session_id' => $this->session->id, 'kind' => TranscriptKind::Trial, 'provider' => 'soniox',
            'status' => TranscriptStatus::Completed, 'language' => 'ur', 'text' => 'آپ کیسے ہیں', 'meta' => ['model' => 'stt-async-v5']]);
        UsageLedgerEntry::query()->create(['user_id' => $this->admin->id, 'transcript_id' => $run->id, 'source' => 'server', 'report_id' => 'final-'.$run->id,
            'use_case' => 'final_transcript', 'provider' => 'soniox', 'duration_ms' => 60_000, 'channels' => 2, 'billed_ms' => 120_000,
            'unit_price_usd' => 0.10, 'cost_usd' => 0.0033, 'billable' => true, 'occurred_at' => now()]);

        $results = app(EngineComparison::class)->results($this->session);
        expect($results[0])->toMatchArray(['label' => 'Soniox', 'model' => 'stt-async-v5', 'status' => 'completed', 'cost_usd' => 0.0033, 'minutes' => 2.0, 'text' => 'آپ کیسے ہیں']);

        app(EngineComparison::class)->pick($run);
        expect(app(LanguageRouter::class)->routes('routing.final', LanguageRouter::DEFAULT_FINAL_ROUTES)['ur'][0])->toBe('soniox');

        app(EngineComparison::class)->useAsTranscript($run);
        $this->session->refresh();
        expect($this->session->finalTranscript->id)->toBe($run->id)
            ->and($this->session->transcripts()->where('kind', 'alternate')->count())->toBe(1)
            ->and($this->session->final_provider)->toBe('soniox');
    });

    it('offers Compare engines, Engine results and the wrong-script fix on the recording page', function () {
        Queue::fake();
        $this->session->finalTranscript->update(['language' => 'ur', 'meta' => ['script_warning' => 'devanagari']]);
        $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $this->session->id]);

        $page->assertActionVisible('wrong_script')->assertActionVisible('compare_engines')->assertActionHidden('engine_results');
        // Urdu's list is Groq, Soniox, ElevenLabs: without a Groq key here, Soniox is next.
        $page->callAction('wrong_script')->assertNotified('Second version queued with Soniox');

        $page->callAction('compare_engines', data: ['language' => 'ur', 'engines' => ['elevenlabs', 'soniox']])->assertHasNoActionErrors();
        expect($this->session->trials()->pluck('provider')->sort()->values()->all())->toBe(['elevenlabs', 'soniox']);
        $results = $this->panelComponent(ViewCaptureSession::class, ['record' => $this->session->id])->assertActionVisible('engine_results');
        $html = $this->modalHtml($results->mountAction('engine_results'));
        expect($html)->toContain('ElevenLabs Scribe')->toContain('Soniox')->toContain('Cost: not known yet');
    });

    it('saves the engine order per language from Settings', function () {
        $page = $this->panelComponent(Settings::class)->set('activeTab', 'recording');

        $page->fillForm(['route_ur_1' => 'soniox', 'route_ur_2' => 'elevenlabs', 'route_ur_3' => null, 'route_ur_4' => null])->call('save')->assertHasNoFormErrors();

        expect(app(SettingsRepository::class)->get('routing.final')['ur'])->toBe(['soniox', 'elevenlabs'])
            ->and(app(SettingsRepository::class)->get('routing.final')['en'])->toBe(['assemblyai', 'groq', 'elevenlabs', 'soniox']);
    });
});

<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\AccuracyLab as LabPage;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\MakeTranscriptViewsJob;
use App\Jobs\ScoreAccuracyJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\Accuracy\AccuracyLab;
use App\Services\Accuracy\ErrorRates;
use App\Services\Accuracy\ReferenceText;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Services\Views\TranscriptConverter;
use App\Services\Views\TranscriptViews;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * Accuracy Lab (v1.2, owner only): engines scored against the owner's reference transcripts; "Apply winners" puts the
 * best engine first for each category. Since v1.5 the Roman Urdu view is scored on its own, recordings count in a
 * category (Urdu, English, mixed) and references can be uploaded (TurboScribe and subtitle files).
 */

function labRun(CaptureSession $session, string $provider, string $text, int $seconds): Transcript
{
    $run = $session->allTranscripts()->create(['kind' => TranscriptKind::Trial, 'provider' => $provider, 'status' => TranscriptStatus::Completed,
        'language' => 'ur', 'text' => $text, 'meta' => ['comparison' => true],
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => $text]]]);
    $run->forceFill(['created_at' => now()->subSeconds($seconds), 'completed_at' => now()])->save();

    return $run;
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('counts the words and letters an engine got wrong, after folding case, punctuation and Urdu spelling', function () {
    expect(ErrorRates::wer('one two three four', 'one too three four'))->toBe(0.25)
        ->and(ErrorRates::wer('one two three four', 'one two four'))->toBe(0.25)
        ->and(ErrorRates::wer('one two', 'one two extra words'))->toBe(1.0)
        ->and(ErrorRates::wer('Kal ki MEETING, theek?', 'kal ki meeting theek'))->toBe(0.0)
        ->and(ErrorRates::wer('ميں کل آؤں گا', 'میں کل آؤں گا'))->toBe(0.0)
        ->and(ErrorRates::cer('abcd', 'abed'))->toBe(0.25)
        ->and(ErrorRates::wer('', ''))->toBe(0.0)
        ->and(ErrorRates::words(str_repeat('word ', ErrorRates::MAX_WORDS + 50)))->toHaveCount(ErrorRates::MAX_WORDS);
});

it('scores each comparison run against the reference and names the best engine per language', function () {
    Queue::fake();
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'میں کل invoice بھیجوں گا']],
        ['status' => 'completed', 'language' => 'ur', 'duration_ms' => 600_000], transcript: ['language' => 'ur']);
    app(AccuracyLab::class)->setReference($session, 'میں کل invoice بھیجوں گا');
    Queue::assertPushed(ScoreAccuracyJob::class);
    labRun($session, 'groq', 'میں کل invoice بھیجوں گا', 60);
    labRun($session, 'assemblyai', 'میں کل انوائس بھیجوں', 120);

    (new ScoreAccuracyJob($session->id))->handle(app(AccuracyLab::class));

    $report = app(AccuracyLab::class)->report($admin->current_workspace_id);
    $urdu = collect($report['languages'])->firstWhere('language', 'ur');
    expect($urdu['winner'])->toBe('groq')
        ->and($urdu['engines'][0])->toMatchArray(['provider' => 'groq', 'recordings' => 1, 'wer' => 0.0, 'seconds_per_audio_minute' => 6.0])
        ->and($urdu['engines'][1]['wer'])->toBe(0.4)
        ->and($report['recordings'][0]['scores'])->toHaveKeys(['groq', 'assemblyai']);

    $changed = app(AccuracyLab::class)->useWinners($admin->current_workspace_id);
    expect($changed[0]['language'])->toBe('Urdu')
        ->and(app(SettingsRepository::class)->get('routing.final')['ur'][0])->toBe('groq');
});

it('scores a comparison run of a reference recording as soon as it is done', function () {
    Queue::fake();
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello there']], ['status' => 'completed']);
    $session->forceFill(['reference_text' => 'Hello there', 'reference_set_at' => now()])->save();
    $run = $session->allTranscripts()->create(['kind' => TranscriptKind::Trial, 'provider' => 'groq', 'status' => TranscriptStatus::Processing, 'language' => 'en']);

    app(TranscriptStore::class)->complete($run, new NormalizedTranscript('Me: Hello there', [new Segment(1, 'Me', 0, 1000, 'Hello there')], 'en'));

    Queue::assertPushed(ScoreAccuracyJob::class, fn (ScoreAccuracyJob $job): bool => $job->sessionId === $session->id);
});

it('lets the owner set the reference on the recording page and shows the Lab', function () {
    Queue::fake();
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Helo thare']], ['status' => 'completed']);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('accuracy_reference', data: ['reference' => 'Hello there'])
        ->assertNotified('Reference saved. Run "Compare engines" to score the engines on this recording.');
    expect($session->fresh()->reference_text)->toBe('Hello there');

    $this->get(LabPage::getUrl())->assertOk()->assertSee('How to test the engines')->assertSee($session->displayTitle());
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->callAction('accuracy_reference_clear');
    expect($session->fresh()->reference_text)->toBeNull();
});

it('is for the owner only', function () {
    $this->actingAs($this->makeRegularUser(), 'web');

    $this->get('/admin/accuracy-lab')->assertForbidden();
});

// --- v1.5: the original and the Roman Urdu view apart, categories, Apply winners, TurboScribe texts -----------------

/** A comparison run of [$session] with one line, its Roman Urdu view made (ready) when [$roman] is given. */
function labV15Run(CaptureSession $session, string $provider, string $text, ?string $roman = null, ?string $language = 'ur'): Transcript
{
    $run = labRun($session, $provider, $text, 60);
    $run->update(['language' => $language]);
    if ($roman !== null) {
        $run->update(['meta' => [...($run->meta ?? []), 'views' => [
            'status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($run), 'model' => 'claude-sonnet-5-5', 'made_at' => now()->toAtomString(),
            'kept' => [], 'roman' => ['0' => $roman], 'urdu' => ['0' => $text], 'english' => ['0' => $text],
        ]]]);
    }

    return $run->refresh();
}

function labV15Session(object $test, ?string $language): CaptureSession
{
    return $test->sessionWithTranscript($test->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'x']],
        ['status' => 'completed', 'language' => $language, 'duration_ms' => 600_000], transcript: ['language' => $language]);
}

it('scores the original transcript and the Roman Urdu view apart', function () {
    Queue::fake();
    $this->admin = $this->signInAsAdmin();
    $session = labV15Session($this, 'ur');
    app(AccuracyLab::class)->setReference($session, 'میں کل invoice بھیجوں گا', 'main kal invoice bhejoon ga');
    // Groq's original is right but its Roman Urdu view loses a word; Gemini's original misses a word, its view is right.
    labV15Run($session, 'groq', 'میں کل invoice بھیجوں گا', 'main kal bhejoon ga');
    labV15Run($session, 'gemini', 'میں کل invoice بھیجوں', 'main kal invoice bhejun ga');

    app(AccuracyLab::class)->scoreSession($session->refresh());

    $groq = $session->trials()->where('provider', 'groq')->sole()->meta['score'];
    expect($groq['original'])->toMatchArray(['wer' => 0.0, 'cer' => 0.0, 'words' => 5])
        ->and($groq['roman'])->toMatchArray(['wer' => 0.2, 'words' => 5]);
    $report = app(AccuracyLab::class)->report($this->admin->current_workspace_id);
    $urdu = collect($report['languages'])->firstWhere('language', 'ur');
    $gemini = collect($urdu['engines'])->firstWhere('provider', 'gemini');
    expect($urdu['roman'])->toBeTrue()
        ->and($urdu['winner'])->toBe('groq')
        ->and($gemini)->toMatchArray(['wer' => 0.2, 'roman_wer' => 0.0, 'recordings' => 1, 'roman_recordings' => 1])
        // "bhejoon" and "bhejun" are the same word once Roman Urdu spelling is folded.
        ->and(ErrorRates::wer('bhejoon theek zaroor', 'bhejun thik zarur', true))->toBe(0.0)
        ->and(ErrorRates::wer('bhejoon theek', 'bhejun thik'))->toBe(1.0)
        ->and($report['recordings'][0]['scores']['gemini'])->toMatchArray(['wer' => 0.2, 'roman_wer' => 0.0]);
    $this->get(LabPage::getUrl())->assertOk()->assertSee('Roman Urdu WER')->assertSee('Google Gemini');
});

it('has a run\'s Roman Urdu view made when it has none, and scores it once it is ready', function () {
    Queue::fake();
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-key-1234567890');
    $this->admin = $this->signInAsAdmin();
    $session = labV15Session($this, null);
    app(AccuracyLab::class)->setReference($session, 'میں کل invoice بھیجوں گا', 'main kal invoice bhejoon ga');
    $run = labV15Run($session, 'groq', 'میں کل invoice بھیجوں گا');

    app(AccuracyLab::class)->scoreSession($session->refresh());

    Queue::assertPushed(MakeTranscriptViewsJob::class, fn (MakeTranscriptViewsJob $job): bool => $job->transcriptId === $run->id);
    expect($run->refresh()->meta['score'])->toHaveKey('original')->not->toHaveKey('roman')
        ->and($run->meta['score']['roman_pending'])->toBeTrue();

    // The view is made (a converter stands in for Claude): the recording is scored again.
    app()->instance(TranscriptConverter::class, new class implements TranscriptConverter
    {
        public function convert(array $lines, string $language, array $views): array
        {
            return ['lines' => array_map(fn (): array => array_intersect_key(['roman' => 'main kal invoice bhejun ga', 'urdu' => 'میں کل invoice بھیجوں گا', 'english' => 'I will send the invoice'], array_flip($views)), $lines),
                'input_tokens' => 100, 'output_tokens' => 100, 'model' => 'claude-sonnet-5-5'];
        }
    });
    (new MakeTranscriptViewsJob($run->id))->handle(app(TranscriptViews::class));
    Queue::assertPushed(ScoreAccuracyJob::class, 2);
    (new ScoreAccuracyJob($session->id))->handle(app(AccuracyLab::class));

    expect($run->refresh()->meta['score']['roman'])->toMatchArray(['wer' => 0.0])
        ->and($run->meta['score'])->not->toHaveKey('roman_pending')
        ->and(collect(app(AccuracyLab::class)->report($this->admin->current_workspace_id)['languages'])->pluck('language')->all())->toBe(['auto']);
});

it('scores in categories and applies each winner at the front of its list, the other engines keeping their order', function () {
    Queue::fake();
    $this->admin = $this->signInAsAdmin();
    $routes = app(SettingsRepository::class)->get('routing.final');
    $urdu = labV15Session($this, 'ur');
    $english = labV15Session($this, 'en');
    $mixed = labV15Session($this, null);
    $chosen = labV15Session($this, 'ur');
    $lab = app(AccuracyLab::class);
    $lab->setReference($urdu, 'میں کل invoice بھیجوں گا');
    $lab->setReference($english, 'I will send the invoice tomorrow');
    $lab->setReference($mixed, 'main kal invoice send karunga');
    // An Urdu recording the owner counts as mixed.
    $lab->setReference($chosen, 'kal meeting hai, invoice bhi', null, 'auto');
    labV15Run($urdu, 'soniox', 'میں کل invoice بھیجوں گا');
    labV15Run($urdu, 'groq', 'میں کل انوائس بھیجوں گا');
    labV15Run($english, 'groq', 'I will send the invoice tomorrow', null, 'en');
    labV15Run($english, 'assemblyai', 'I will send invoice tomorrow', null, 'en');
    labV15Run($mixed, 'gemini', 'main kal invoice send karunga', null, null);
    labV15Run($mixed, 'groq', 'main kal invoice send karta', null, null);
    labV15Run($chosen, 'gemini', 'kal meeting hai, invoice bhi', null, 'ur');
    foreach ([$urdu, $english, $mixed, $chosen] as $session) {
        $lab->scoreSession($session->refresh());
    }

    $report = $lab->report($this->admin->current_workspace_id);
    expect(collect($report['languages'])->pluck('label', 'language')->all())->toBe(['ur' => 'Urdu', 'en' => 'English', 'auto' => 'Mixed (Urdu and English)'])
        ->and(collect($report['languages'])->pluck('winner', 'language')->all())->toBe(['ur' => 'soniox', 'en' => 'groq', 'auto' => 'gemini'])
        ->and(collect($report['languages'])->firstWhere('language', 'auto')['engines'][0]['recordings'])->toBe(2);

    Livewire::test(LabPage::class)->callAction('apply_winners')->assertNotified();

    $after = app(SettingsRepository::class)->get('routing.final');
    expect($after['ur'])->toBe(['soniox', ...array_values(array_diff($routes['ur'], ['soniox']))])
        ->and($after['en'])->toBe(['groq', ...array_values(array_diff($routes['en'], ['groq']))])
        ->and($after['auto'])->toBe(['gemini', ...$routes['auto']])
        ->and($after['ar'])->toBe($routes['ar']);
});

it('reads TurboScribe and subtitle texts: only the spoken words stay', function () {
    $srt = "1\n00:00:01,000 --> 00:00:03,500\nSpeaker 1: Assalam o alaikum, kaise hain?\n\n2\n00:00:04,000 --> 00:00:06,000\nTheek hoon, shukriya.\n";
    $vtt = "WEBVTT\n\nNOTE made by TurboScribe\n\n00:01.000 --> 00:03.500\n<v Speaker 1>Hello there.</v>\n\n00:04.000 --> 00:06.000\nHi, thanks for calling.\n";
    $turbo = "Speaker 1 (00:00)\nMain kal invoice bhejunga.\n\nSpeaker 2 (00:05)\n(00:06) Theek hai, 2 baje.\n[01:02:03] SPEAKER_00: Achha.";

    expect(ReferenceText::clean($srt))->toBe("Assalam o alaikum, kaise hain?\nTheek hoon, shukriya.")
        ->and(ReferenceText::clean($vtt))->toBe("Hello there.\nHi, thanks for calling.")
        ->and(ReferenceText::clean($turbo))->toBe("Main kal invoice bhejunga.\nTheek hai, 2 baje.\nAchha.")
        ->and(ReferenceText::clean("\u{FEFF}Me: میں کل آؤں گا\nClient: ٹھیک ہے"))->toBe("میں کل آؤں گا\nٹھیک ہے");

    $docx = tempnam(sys_get_temp_dir(), 'ref').'.docx';
    $zip = new ZipArchive;
    $zip->open($docx, ZipArchive::CREATE);
    $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Speaker 1 (00:00)</w:t></w:r></w:p><w:p><w:r><w:t>Kal &amp; parson</w:t></w:r><w:r><w:tab/><w:t>meeting</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    try {
        expect(ReferenceText::fromFile($docx, 'call.docx'))->toBe('Kal & parson meeting');
    } finally {
        @unlink($docx);
    }
    expect(fn () => ReferenceText::fromFile($docx, 'call.pdf'))->toThrow(RuntimeException::class, 'Upload a .txt, .srt, .vtt or .docx file.');
});

it('takes an uploaded TurboScribe file on the recording page, with a Roman Urdu reference and a category', function () {
    Queue::fake();
    $this->admin = $this->signInAsAdmin();
    $session = labV15Session($this, 'ur');
    $file = UploadedFile::fake()->createWithContent('call.srt', "1\n00:00:01,000 --> 00:00:03,500\nSpeaker 1: میں کل invoice بھیجوں گا\n");

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('accuracy_reference', data: ['category' => 'auto', 'reference' => 'ignored when a file is uploaded', 'reference_file' => $file, 'reference_roman' => 'Speaker 1: main kal invoice bhejoon ga'])
        ->assertNotified('Reference saved. Run "Compare engines" to score the engines on this recording.');

    $session->refresh();
    expect($session->reference_text)->toBe('میں کل invoice بھیجوں گا')
        ->and($session->reference_roman)->toBe('main kal invoice bhejoon ga')
        ->and($session->reference_category)->toBe('auto')
        ->and(app(AccuracyLab::class)->category($session))->toBe('auto');

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->callAction('accuracy_reference_clear');
    expect($session->fresh()->hasReference())->toBeFalse()->and($session->fresh()->reference_roman)->toBeNull();
});

it('names no winner and applies nothing while no engine has a score yet', function () {
    Queue::fake();
    $this->admin = $this->signInAsAdmin();
    $routes = app(SettingsRepository::class)->get('routing.final');
    $session = labV15Session($this, 'ur');
    // Only TurboScribe's Roman Urdu text, and no Anthropic key: the runs' Roman Urdu views cannot be made.
    app(AccuracyLab::class)->setReference($session, '', 'main kal invoice bhejun ga', 'ur');
    labV15Run($session, 'gemini', 'میں کل انوائس');
    labV15Run($session, 'groq', 'میں کل invoice بھیجوں گا');
    app(AccuracyLab::class)->scoreSession($session->refresh());

    $urdu = collect(app(AccuracyLab::class)->report($this->admin->current_workspace_id)['languages'])->firstWhere('language', 'ur');
    expect($urdu['winner'])->toBeNull()
        ->and(collect($urdu['engines'])->pluck('roman_wer')->all())->toBe([null, null])
        ->and(app(AccuracyLab::class)->useWinners($this->admin->current_workspace_id))->toBe([])
        ->and(app(SettingsRepository::class)->get('routing.final')['ur'])->toBe($routes['ur']);

    // With the key the views are being made: still nothing to apply.
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-key-1234567890');
    app(AccuracyLab::class)->setReference($session, '', 'main kal invoice bhejoon ga', 'ur');
    app(AccuracyLab::class)->scoreSession($session->refresh());
    expect($session->trials()->get()->every(fn (Transcript $run): bool => ($run->meta['score']['roman_pending'] ?? false) === true))->toBeTrue()
        ->and(app(AccuracyLab::class)->useWinners($this->admin->current_workspace_id))->toBe([])
        ->and(app(SettingsRepository::class)->get('routing.final')['ur'])->toBe($routes['ur']);
    $this->get(LabPage::getUrl())->assertOk()->assertSee('not scored yet');
});

it('keeps an Arabic recording\'s reference in Arabic, so its winner never reorders the list for mixed calls', function () {
    Queue::fake();
    $this->admin = $this->signInAsAdmin();
    $routes = app(SettingsRepository::class)->get('routing.final');
    $session = labV15Session($this, 'ar');

    // Saved as offered: the dialog starts in the recording's own language, which is one of its choices.
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('accuracy_reference', data: ['reference' => 'مرحبا كيف حالك'])
        ->assertNotified('Reference saved. Run "Compare engines" to score the engines on this recording.');
    expect($session->refresh()->reference_category)->toBeNull()
        ->and(app(AccuracyLab::class)->category($session))->toBe('ar')
        ->and(app(AccuracyLab::class)->categoryOptions($session))->toBe([...AccuracyLab::CATEGORIES, 'ar' => 'Arabic']);
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('accuracy_reference', data: ['category' => 'ar', 'reference' => 'مرحبا كيف حالك'])
        ->assertNotified('Reference saved. Run "Compare engines" to score the engines on this recording.');

    labV15Run($session, 'groq', 'مرحبا كيف حالك', null, 'ar');
    app(AccuracyLab::class)->scoreSession($session->refresh());

    expect(app(AccuracyLab::class)->useWinners($this->admin->current_workspace_id))->toBe([['language' => 'Arabic', 'provider' => 'Groq Whisper large-v3']]);
    $after = app(SettingsRepository::class)->get('routing.final');
    expect($after['ar'])->toBe(['groq', ...array_values(array_diff($routes['ar'], ['groq']))])
        ->and($after['auto'])->toBe($routes['auto']);
});

it('never reorders the list the other languages share', function () {
    Queue::fake();
    $this->admin = $this->signInAsAdmin();
    $routes = app(SettingsRepository::class)->get('routing.final');
    $session = labV15Session($this, 'fr');
    // A reference from before v1.5: no category, so it counts in its own language.
    $session->forceFill(['reference_text' => 'bonjour à tous', 'reference_set_at' => now()])->save();
    labV15Run($session, 'groq', 'bonjour à tous', null, 'fr');
    app(AccuracyLab::class)->scoreSession($session->refresh());

    $other = collect(app(AccuracyLab::class)->report($this->admin->current_workspace_id)['languages'])->firstWhere('language', 'other');
    expect($other['winner'])->toBe('groq')
        ->and(app(AccuracyLab::class)->useWinners($this->admin->current_workspace_id))->toBe([])
        ->and(app(SettingsRepository::class)->get('routing.final')['other'])->toBe($routes['other']);
});

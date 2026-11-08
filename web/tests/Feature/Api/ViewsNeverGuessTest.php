<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\MakeTranscriptViewsJob;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\Views\ClaudeTranscriptConverter;
use App\Services\Views\TranscriptConverter;
use App\Services\Views\TranscriptViews;
use App\Support\UnsureMarkers;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Phase 1C H3: the views never guess. A word two engines heard differently reaches the model with the other version
 * ("[?أفحم|probably أفهم]"); the views keep it flagged ("I [?understand] English"), never a smooth sentence, never the
 * alternative itself, and the pair becomes a suggested fix.
 */

/** The 8:19 pm note after the short-note check: «أفحم» unsure, a third engine agreeing with «أفهم». */
function checkedArabicNote(object $test, int $ms = 2000): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(2000), ['language' => 'ar', 'source_app' => 'voice_note', 'duration_ms' => $ms]);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'elevenlabs', 'status' => TranscriptStatus::Completed, 'language' => 'ar',
        'text' => 'Speaker: أنا أفحم الإنجليزية',
        'segments' => [['channel' => 1, 'speaker' => 'Speaker', 'start_ms' => 0, 'end_ms' => 2000, 'text' => 'أنا أفحم الإنجليزية', 'uncertain' => true, 'words' => [
            ['text' => 'أنا', 'start_ms' => 0, 'end_ms' => 400, 'confidence' => 0.95],
            ['text' => 'أفحم', 'start_ms' => 450, 'end_ms' => 1100, 'confidence' => 0.2, 'alt' => 'أفهم', 'alt_by' => 'groq', 'probably' => 'أفهم', 'tie_by' => 'gemini'],
            ['text' => 'الإنجليزية', 'start_ms' => 1150, 'end_ms' => 2000, 'confidence' => 0.93],
        ]]]]);
}

beforeEach(function () {
    [$this->device] = $this->enroll(platform: 'android');
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
});

it('sends an unsure word with the other engine\'s version, and the export keeps the plain mark', function () {
    $segment = checkedArabicNote($this)->segments[0];

    expect(UnsureMarkers::markForViews($segment, 0.5))->toBe('أنا [?أفحم|probably أفهم] الإنجليزية')
        ->and(UnsureMarkers::mark($segment, 0.5))->toBe('أنا [?أفحم] الإنجليزية')
        ->and(TranscriptViews::withoutAlternatives('I [?understand|probably understand] English'))->toBe('I [?understand] English');
});

it('keeps the doubtful word flagged in the English view and turns the pair into a suggested fix', function () {
    $sent = [];
    app()->instance(TranscriptConverter::class, new class($sent) implements TranscriptConverter
    {
        public function __construct(public array &$sent) {}

        public function convert(array $lines, string $language, array $views): array
        {
            $this->sent = $lines;

            // A model that followed the rules, except that it copied the alternative into one view.
            return ['lines' => [0 => ['english' => 'I [?understand] English', 'urdu' => 'میں [?سمجھتا|probably سمجھتا] ہوں انگریزی']],
                'misheard' => [0 => [['from' => 'أفحم', 'to' => 'أفهم']]], 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'claude-sonnet-5-5'];
        }
    });
    $transcript = checkedArabicNote($this);

    (new MakeTranscriptViewsJob($transcript->id))->handle(app(TranscriptViews::class));

    $views = $transcript->refresh()->meta['views'];
    expect(app(TranscriptConverter::class)->sent[0])->toBe('أنا [?أفحم|probably أفهم] الإنجليزية')
        ->and($views['english'][0])->toBe('I [?understand] English')
        ->and($views['english'][0])->not->toContain('studying')
        ->and($views['urdu'][0])->not->toContain('|');
    expect(collect($transcript->meta['suggestions'] ?? [])->firstWhere('reason', 'view_check'))
        ->toMatchArray(['line' => 0, 'from' => 'أفحم', 'to' => 'أفهم', 'status' => 'open']);
});

it('uses the short-note model only for notes of 3 minutes or less, and only once it is set', function () {
    app(SettingsRepository::class)->set('claude.model', 'claude-sonnet-5-5');
    $converter = new class(app(CredentialVault::class), app(SettingsRepository::class)) extends ClaudeTranscriptConverter
    {
        public array $models = [];

        public function convert(array $lines, string $language, array $views): array
        {
            $this->models[] = $this->model();

            return ['lines' => [], 'input_tokens' => 1, 'output_tokens' => 1, 'model' => $this->model()];
        }
    };
    app()->instance(TranscriptConverter::class, $converter);
    $views = fn () => app(TranscriptViews::class);

    // Each make may ask more than once (a line that came back empty is asked again): the model of each make is what counts.
    $modelOf = function (Transcript $t) use ($converter, $views): array {
        $converter->models = [];
        $views()->make($t);

        return array_values(array_unique($converter->models));
    };
    expect($modelOf(checkedArabicNote($this)))->toBe(['claude-sonnet-5-5']);
    app(SettingsRepository::class)->set('views.short_model', 'claude-haiku-5-5');
    expect($modelOf(checkedArabicNote($this)))->toBe(['claude-haiku-5-5'])
        ->and($modelOf(checkedArabicNote($this, 600_000)))->toBe(['claude-sonnet-5-5']);
});

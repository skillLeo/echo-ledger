<?php

declare(strict_types=1);

use App\Console\Commands\ViewsCheck;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Views\TranscriptConverter;
use App\Services\Views\TranscriptViews;

/* echoledger:views-check (since v1.5, Phase 1C, E2): how many view lines differ from the original word for word. */

/**
 * @param  list<string>  $lines
 * @param  array<string, list<string>>  $views
 */
function viewsCheckTranscript(string $title, array $lines, array $views, int $minutesAgo = 0): Transcript
{
    $user = User::query()->first() ?? User::factory()->create();
    $session = CaptureSession::factory()->create(['user_id' => $user->id, 'title' => $title, 'status' => 'completed', 'language' => 'ur', 'started_at' => now()->subMinutes($minutesAgo + 5)]);
    $segments = array_map(fn (string $text, int $i): array => ['channel' => 1, 'speaker' => 'Me', 'start_ms' => $i * 4000, 'end_ms' => $i * 4000 + 3000, 'text' => $text], $lines, array_keys($lines));
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed,
        'language' => 'ur', 'text' => implode("\n", $lines), 'segments' => $segments]);
    $transcript->forceFill(['completed_at' => now()->subMinutes($minutesAgo)])->save();
    if ($views !== []) {
        $made = ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($transcript), 'model' => 'claude-sonnet-5-5', 'made_at' => now()->toAtomString(), 'kept' => []];
        foreach ($views as $view => $texts) {
            $made[$view] = array_combine(array_map('strval', array_keys($texts)), $texts);
        }
        $transcript->forceFill(['meta' => ['views' => $made]])->save();
    }

    return $transcript->refresh();
}

const VIEWS_CHECK_LINES = ['میں تعل رہا ہوں', 'مجھے سولہ جی بی والا چاہیے', 'ٹھیک ہے، شکریہ'];

it('counts the transliterated lines whose words differ, and only those', function () {
    $check = ViewsCheck::compare(
        array_map(fn (string $t, int $i): array => ['text' => $t, 'start_ms' => $i * 4000], VIEWS_CHECK_LINES, [0, 1, 2]),
        ['roman' => ['main dekh raha hoon', 'mujhe 16 GB wala chahiye', 'theek hai, shukriya bhai'], 'urdu' => VIEWS_CHECK_LINES],
    );

    expect($check)->toMatchArray(['views' => ['roman' => 2, 'urdu' => 0], 'lines' => 6, 'differ' => 2, 'words' => 2])
        ->and($check['examples'][0])->toBe(['view' => 'Roman Urdu', 'index' => 0, 'start_ms' => 0, 'words' => 1]);
});

it('reports per recording and in total, makes no AI call and writes nothing', function () {
    $converter = Mockery::mock(TranscriptConverter::class);
    $converter->shouldNotReceive('convert');
    app()->instance(TranscriptConverter::class, $converter);
    $changed = viewsCheckTranscript('Laptop call', VIEWS_CHECK_LINES, ['roman' => ['main dekh raha hoon', 'mujhe 16 GB wala chahiye', 'theek hai, shukriya'], 'urdu' => VIEWS_CHECK_LINES], 1);
    viewsCheckTranscript('Clean call', VIEWS_CHECK_LINES, ['roman' => ['main tal raha hoon', 'mujhe 16 GB wala chahiye', 'theek hai, shukriya'], 'urdu' => VIEWS_CHECK_LINES], 2);
    viewsCheckTranscript('No views', VIEWS_CHECK_LINES, [], 3);
    $before = Transcript::query()->orderBy('id')->get()->map->getAttributes()->all();

    $this->artisan('echoledger:views-check', ['--limit' => 20])
        ->expectsOutputToContain('Laptop call')
        ->expectsOutputToContain('Clean call')
        ->expectsOutputToContain('Roman Urdu line 1 at 0:00: 1 words differ')
        ->expectsOutputToContain('Total: 1 of 12 view lines differ from the original word for word (8.3 %) in 2 recordings, 1 words.')
        ->doesntExpectOutputToContain('No views')
        ->assertSuccessful();
    expect(Transcript::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and($changed->refresh()->meta['views']['roman']['0'])->toBe('main dekh raha hoon');

    $this->artisan('echoledger:views-check', ['--limit' => 1])->expectsOutputToContain('in 1 recordings')->assertSuccessful();
});

it('says so when no recording has views yet', function () {
    viewsCheckTranscript('No views', VIEWS_CHECK_LINES, []);
    $this->artisan('echoledger:views-check')->expectsOutputToContain('No recording with a Roman Urdu or اردو view yet.')->assertSuccessful();
});

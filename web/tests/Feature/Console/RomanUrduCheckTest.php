<?php

declare(strict_types=1);

use App\Console\Commands\RomanUrduCheck;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\Views\TranscriptConverter;
use App\Services\Views\TranscriptViews;

/*
 * echoledger:roman-urdu-check (since v1.5): does the Roman Urdu view keep the words of the original? Read-only apart
 * from --generate, which makes the missing views first.
 */

/**
 * A finished final transcript of a new recording, its Roman Urdu view made (ready) when [$roman] is given.
 *
 * @param  list<string>  $lines
 * @param  list<string>|null  $roman
 */
function romanCheckTranscript(string $title, array $lines, ?array $roman, ?string $language = 'ur', int $minutesAgo = 0): Transcript
{
    $user = User::query()->first() ?? User::factory()->create();
    $session = CaptureSession::factory()->create(['user_id' => $user->id, 'title' => $title, 'status' => 'completed', 'language' => $language, 'started_at' => now()->subMinutes($minutesAgo + 5)]);
    $segments = array_map(fn (string $text, int $i): array => ['channel' => 1, 'speaker' => 'Me', 'start_ms' => $i * 4000, 'end_ms' => $i * 4000 + 3000, 'text' => $text], $lines, array_keys($lines));
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed,
        'language' => $language, 'text' => implode("\n", $lines), 'segments' => $segments]);
    $transcript->forceFill(['completed_at' => now()->subMinutes($minutesAgo)])->save();
    if ($roman !== null) {
        $transcript->forceFill(['meta' => ['views' => [
            'status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($transcript), 'model' => 'claude-sonnet-5-5', 'made_at' => now()->toAtomString(), 'kept' => [],
            'roman' => array_combine(array_map('strval', array_keys($roman)), $roman), 'urdu' => [], 'english' => [],
        ]]])->save();
    }

    return $transcript->refresh();
}

const ROMAN_CHECK_LINES = ['میں کل آپ کو invoice بھیجوں گا', 'ٹھیک ہے، شکریہ', 'ہم اگلے ہفتے meeting کریں گے'];

it('counts the words of each line in the original and the view, and gives a verdict', function () {
    $good = RomanUrduCheck::compare(
        array_map(fn (string $t): array => ['text' => $t, 'start_ms' => 0], ROMAN_CHECK_LINES),
        ['main kal aap ko invoice bhejoon ga', 'theek hai, shukriya', 'hum aglay haftay meeting karein ge'],
    );
    $lossy = RomanUrduCheck::compare(
        array_map(fn (string $t, int $i): array => ['text' => $t, 'start_ms' => $i * 4000], ROMAN_CHECK_LINES, [0, 1, 2]),
        ['main kal invoice bhejoon ga', 'theek hai, shukriya, bohat bohat shukriya', 'hum aglay haftay meeting karein ge'],
    );

    expect($good)->toMatchArray(['original' => 16, 'roman' => 16, 'lines' => 3, 'off' => 0, 'dropped' => 0, 'added' => 0, 'worst' => []])
        ->and(RomanUrduCheck::verdict($good))->toBe('OK')
        ->and($lossy)->toMatchArray(['original' => 16, 'roman' => 17, 'lines' => 3, 'off' => 2, 'dropped' => 1, 'added' => 1])
        ->and($lossy['worst'][0])->toBe(['index' => 1, 'start_ms' => 4000, 'original' => 3, 'roman' => 6])
        ->and(RomanUrduCheck::verdict($lossy))->toBe('Problem')
        ->and(RomanUrduCheck::verdict(['original' => 100, 'roman' => 93, 'lines' => 10, 'off' => 1]))->toBe('Check');
});

it('reports the latest Urdu and Auto recordings and changes nothing', function () {
    romanCheckTranscript('Call with Omar', ROMAN_CHECK_LINES, ['main kal aap ko invoice bhejoon ga', 'theek hai, shukriya', 'hum aglay haftay meeting karein ge'], 'ur', 30);
    $lossy = romanCheckTranscript('Call with Sana', ROMAN_CHECK_LINES, ['main kal invoice bhejoon ga', 'theek hai', 'hum meeting karein ge'], null, 10);
    romanCheckTranscript('English call', ['We will send the invoice tomorrow'], null, 'en', 5);
    $before = Transcript::query()->orderBy('id')->get(['id', 'meta', 'updated_at'])->toArray();

    $this->artisan('echoledger:roman-urdu-check')
        ->expectsOutputToContain('Call with Omar')
        ->expectsOutputToContain('Call with Sana')
        ->expectsOutputToContain('line 3 at 0:08: 6 words in the original, 4 in Roman Urdu')
        ->expectsOutputToContain('Overall: Problem (2 recordings: 1 OK, 0 to check, 1 with a problem).')
        ->doesntExpectOutputToContain('English call')
        ->assertSuccessful();

    expect(Transcript::query()->orderBy('id')->get(['id', 'meta', 'updated_at'])->toArray())->toBe($before)
        ->and($lossy->refresh()->meta['views']['roman'])->toHaveCount(3);
});

it('takes only the latest recordings, and says which have no view yet', function () {
    romanCheckTranscript('Older call', ROMAN_CHECK_LINES, ['main kal aap ko invoice bhejoon ga', 'theek hai, shukriya', 'hum aglay haftay meeting karein ge'], 'ur', 60);
    romanCheckTranscript('Newer call', ROMAN_CHECK_LINES, ['main kal aap ko invoice bhejoon ga', 'theek hai, shukriya', 'hum aglay haftay meeting karein ge'], 'ur', 20);
    romanCheckTranscript('No view yet', ROMAN_CHECK_LINES, null, 'ur', 1);

    $this->artisan('echoledger:roman-urdu-check', ['--limit' => 1])
        ->expectsOutputToContain('Newer call')
        ->doesntExpectOutputToContain('Older call')
        ->expectsOutputToContain('Overall: OK (1 recordings: 1 OK, 0 to check, 0 with a problem).')
        ->expectsOutputToContain('1 recent Urdu or Auto recordings have no Roman Urdu view yet: run with --generate')
        ->assertSuccessful();
});

it('makes the missing views with --generate, and needs the Anthropic key for it', function () {
    $transcript = romanCheckTranscript('No view yet', ROMAN_CHECK_LINES, null, 'ur');

    $this->artisan('echoledger:roman-urdu-check', ['--generate' => true])->expectsOutputToContain('Anthropic')->assertFailed();
    expect($transcript->refresh()->meta['views'] ?? null)->toBeNull();

    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-key-1234567890');
    app()->instance(TranscriptConverter::class, new class implements TranscriptConverter
    {
        public function convert(array $lines, string $language, array $views): array
        {
            $roman = ['main kal aap ko invoice bhejoon ga', 'theek hai, shukriya', 'hum aglay haftay meeting karein ge'];

            return ['lines' => array_map(fn (string $text, int $i): array => array_intersect_key(['roman' => $roman[$i], 'urdu' => $text, 'english' => 'English'], array_flip($views)), $lines, array_keys($lines)),
                'input_tokens' => 100, 'output_tokens' => 100, 'model' => 'claude-sonnet-5-5'];
        }
    });

    $this->artisan('echoledger:roman-urdu-check', ['--generate' => true])
        ->expectsOutputToContain('No view yet')
        ->expectsOutputToContain('Overall: OK')
        ->assertSuccessful();
    expect($transcript->refresh()->meta['views']['status'])->toBe('ready');
});

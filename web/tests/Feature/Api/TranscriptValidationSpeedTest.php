<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "POST /api/sessions/{id}/transcripts": at most 20 000 segments. The cap must be reachable: validation is
 * linear, so a full-size upload is checked (and an oversize one refused) in time proportional to its size.
 */

beforeEach(function () {
    [$this->device, $token] = $this->enroll(platform: 'windows');
    $this->asToken($token);
    $this->session = CaptureSession::factory()->create([
        'user_id' => $this->device->user_id, 'device_id' => $this->device->id,
        'duration_ms' => 20000 * 3000, 'status' => SessionStatus::Uploading,
    ]);
    $this->url = "/api/sessions/{$this->session->id}/transcripts";
});

/** @return list<array<string, mixed>> */
function captionSegments(int $count): array
{
    $segments = [];
    for ($i = 0; $i < $count; $i++) {
        $segments[] = ['channel' => $i % 2 + 1, 'start_ms' => $i * 3000, 'end_ms' => $i * 3000 + 2500, 'text' => "line {$i}",
            'words' => [['text' => 'line', 'start_ms' => $i * 3000, 'end_ms' => $i * 3000 + 900, 'confidence' => 0.9]]];
    }

    return $segments;
}

/**
 * Posts `$count` segments to a fresh session and returns the seconds the request took. Wall-clock limits fail on a laptop
 * on battery, so the tests below never compare a time with a fixed budget: they compare it with another request
 * measured in the same process a moment earlier, which slows down by the same factor on a slow machine.
 */
function postedSeconds(mixed $test, int $count, bool $logQueries = false): float
{
    $session = CaptureSession::factory()->create([
        'user_id' => $test->device->user_id, 'device_id' => $test->device->id,
        'duration_ms' => 20000 * 3000, 'status' => SessionStatus::Uploading,
    ]);
    $body = ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => captionSegments($count)];

    if ($logQueries) {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }
    $started = hrtime(true);
    $test->postJson("/api/sessions/{$session->id}/transcripts", $body)->assertCreated();
    $seconds = (hrtime(true) - $started) / 1e9;
    DB::disableQueryLog();

    return $seconds;
}

/** Fastest of `$times` runs: the least disturbed one, which is the real cost of the work. */
function bestOf(int $times, callable $run): float
{
    return min(array_map(fn () => $run(), range(1, $times)));
}

it('validates 20,000 segments in linear time', function () {
    postedSeconds($this, 200); // boots the container, routes and validator once, outside the measurement

    $small = bestOf(3, fn () => postedSeconds($this, 2000));
    $full = bestOf(2, fn () => postedSeconds($this, 20000));

    // Ten times the segments should cost about ten times as much; checking each segment against all the others (the
    // old behaviour) costs about a hundred times as much. The limit sits between the two on any machine speed.
    $ratio = $full / $small;
    expect($ratio)->toBeLessThan(40.0, sprintf('2,000 segments took %.3f s, 20,000 took %.3f s (x%.1f)', $small, $full, $ratio))
        // A hang guard only: no machine takes this long for an upload that is linear.
        ->and($full)->toBeLessThan(60.0);
    expect(Transcript::query()->orderByDesc('id')->first()->segments)->toHaveCount(20000);
});

it('runs the same number of queries for 20,000 segments as for 200', function () {
    postedSeconds($this, 200); // the first upload of a device also warms caches
    postedSeconds($this, 200, logQueries: true);
    $few = count(DB::getQueryLog());
    postedSeconds($this, 20000, logQueries: true);
    $many = count(DB::getQueryLog());

    expect($many)->toBe($few);
});

it('refuses 20,001 segments no slower than it accepts 20,000', function () {
    postedSeconds($this, 200);
    $accepted = bestOf(2, fn () => postedSeconds($this, 20000));

    $body = ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => captionSegments(20001)];
    $refused = bestOf(2, function () use ($body) {
        $started = hrtime(true);
        $this->postJson($this->url, $body)->assertStatus(422)->assertJsonValidationErrors(['segments']);

        return (hrtime(true) - $started) / 1e9;
    });

    expect($refused)->toBeLessThan($accepted * 3 + 0.2, sprintf('accepting took %.3f s, refusing took %.3f s', $accepted, $refused))
        ->and(Transcript::query()->where('capture_session_id', $this->session->id)->count())->toBe(0);
});

it('points at the first bad segment of a full-size upload', function () {
    $segments = captionSegments(20000);
    $segments[19998]['channel'] = 3;
    $segments[19999]['text'] = '';

    $this->postJson($this->url, ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => $segments])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['segments.19998.channel'])
        ->assertJsonMissingValidationErrors(['segments.19999.text']);
});

it('checks every word of a segment', function (mixed $word, string $field) {
    $segments = captionSegments(3);
    $segments[2]['words'][] = $word;

    $this->postJson($this->url, ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => $segments])
        ->assertStatus(422)->assertJsonValidationErrors(["segments.2.words.1{$field}"]);
})->with([
    'not an object' => ['word', ''],
    'text too long' => [['text' => str_repeat('w', 1001)], '.text'],
    'negative start' => [['start_ms' => -5], '.start_ms'],
    'end as text' => [['end_ms' => 'later'], '.end_ms'],
    'confidence above one' => [['confidence' => 1.5], '.confidence'],
    'confidence as text' => [['confidence' => 'high'], '.confidence'],
]);

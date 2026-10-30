<?php

declare(strict_types=1);

use App\Services\SettingsRepository;
use App\Stt\Audio\AudioPrep;
use App\Stt\Data\Segment;
use App\Stt\Data\Word;
use App\Stt\Drivers\GroqWhisperDriver;

/*
 * Long recordings are sent in pieces (v1.3): cuts sit in real pauses (≥ 600 ms); where no pause exists the next piece
 * starts 1 s early and the join keeps every word exactly once.
 */

it('overlaps only the pieces cut inside speech, and keeps a whole piece after a pause', function () {
    $silences = [[299.5, 300.5]];
    $plan = [[0.0, 300.0], [300.0, 600.0], [600.0, 900.0]]; // cut at 300 in a pause, at 600 inside speech

    expect(AudioPrep::overlapHardCuts($plan, $silences))->toBe([
        ['start' => 0.0, 'end' => 300.0, 'keep_from' => 0.0],
        ['start' => 300.0, 'end' => 600.0, 'keep_from' => 300.0],
        ['start' => 599.0, 'end' => 900.0, 'keep_from' => 600.0],
    ]);
});

it('never overlaps across a long silence that was left out', function () {
    // Two pieces of speech with a skipped silence between them do not touch: no overlap.
    expect(AudioPrep::overlapHardCuts([[0.0, 10.0], [25.0, 40.0]], [[10.0, 25.0]]))->toBe([
        ['start' => 0.0, 'end' => 10.0, 'keep_from' => 0.0],
        ['start' => 25.0, 'end' => 40.0, 'keep_from' => 25.0],
    ]);
});

it('keeps each word once when a line crosses a cut inside speech', function () {
    $w = fn (string $t, int $s) => new Word($t, $s, $s + 300);
    $across = new Segment(1, 'Me', 599_100, 601_800, 'mujhe kal invoice bhej dein', [
        $w('mujhe', 599_100), $w('kal', 599_500), $w('invoice', 600_050), $w('bhej', 600_700), $w('dein', 601_200),
    ]);

    $kept = GroqWhisperDriver::keepFrom($across, 600_000);

    expect($kept?->text)->toBe('invoice bhej dein')
        ->and($kept?->startMs)->toBe(600_050)
        ->and(count($kept?->words ?? []))->toBe(3);
});

it('drops a line wholly in the overlap, keeps one after the cut, and keeps a line without word times', function () {
    $before = new Segment(2, 'Client', 599_000, 599_700, 'theek hai');
    $after = new Segment(2, 'Client', 600_100, 602_000, 'phir milte hain');
    $noWords = new Segment(2, 'Client', 599_200, 601_500, 'acha ji');

    expect(GroqWhisperDriver::keepFrom($before, 600_000))->toBeNull()
        ->and(GroqWhisperDriver::keepFrom($after, 600_000))->toBe($after)
        ->and(GroqWhisperDriver::keepFrom($noWords, 600_000))->toBe($noWords)
        ->and(GroqWhisperDriver::keepFrom($before, 0))->toBe($before);
});

it('names the output container for ffmpeg instead of trusting the temp file extension', function () {
    $calls = [];
    $prep = new AudioPrep(app(SettingsRepository::class), function (array $args) use (&$calls): array {
        $calls[] = $args;

        return [0, ''];
    });

    // Temp files end in .bin, which ffmpeg reads as a caption format with no audio stream ("Output file does not
    // contain any stream", production 2026-10-29).
    $prep->extractChannel('in.ogg', 2, 'groq-side-1.bin');
    $prep->cut('side.bin', 1.0, 2.0, 'chunk.flac');

    expect(array_slice($calls[0], -4))->toBe(['-f', 'wav', '-y', 'groq-side-1.bin'])
        ->and(array_slice($calls[1], -4))->toBe(['-f', 'flac', '-y', 'chunk.flac']);
});

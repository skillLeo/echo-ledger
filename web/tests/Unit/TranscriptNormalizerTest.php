<?php

declare(strict_types=1);

use App\Stt\Data\Segment;
use App\Stt\Data\Word;
use App\Stt\TranscriptNormalizer;

function w(string $text, int $start, int $end): Word
{
    return new Word($text, $start, $end);
}

it('labels speakers by channel count', function () {
    expect(TranscriptNormalizer::speakerFor(1, 2))->toBe('Me');
    expect(TranscriptNormalizer::speakerFor(2, 2))->toBe('Client');
    expect(TranscriptNormalizer::speakerFor(1, 1))->toBe('Speaker');
});

it('splits words into segments on long gaps', function () {
    $words = [w('hello', 0, 400), w('there', 450, 900), w('later', 2500, 2900)];
    $segments = TranscriptNormalizer::segmentWords($words, 1, 'Me', gapMs: 1000);
    expect($segments)->toHaveCount(2);
    expect($segments[0]->text)->toBe('hello there');
    expect($segments[1]->text)->toBe('later');
    expect($segments[0]->startMs)->toBe(0);
    expect($segments[0]->endMs)->toBe(900);
});

it('sorts segments by start time then channel', function () {
    $sorted = TranscriptNormalizer::sortByStart([
        new Segment(2, 'Client', 500, 800, 'b'),
        new Segment(1, 'Me', 500, 700, 'a'),
        new Segment(1, 'Me', 0, 400, 'first'),
    ]);
    expect(array_map(fn (Segment $s) => $s->text, $sorted))->toBe(['first', 'a', 'b']);
});

it('drops a Me segment that echoes an overlapping Client segment (acoustic bleed)', function () {
    $segments = [
        new Segment(2, 'Client', 1000, 3000, 'the quarterly numbers look strong this period'),
        new Segment(1, 'Me', 1100, 3100, 'the quarterly numbers look strong this period'),
        new Segment(1, 'Me', 4000, 5000, 'great, I will send the contract over'),
    ];
    $deduped = TranscriptNormalizer::dedupeBleed($segments);
    expect($deduped)->toHaveCount(2);
    expect($deduped[0]->channel)->toBe(2);
    expect($deduped[1]->text)->toContain('contract');
});

it('keeps short Me segments even if they resemble the client', function () {
    $segments = [
        new Segment(2, 'Client', 1000, 1200, 'yes'),
        new Segment(1, 'Me', 1050, 1250, 'yes'),
    ];
    expect(TranscriptNormalizer::dedupeBleed($segments))->toHaveCount(2);
});

it('renders plain text as speaker-prefixed lines', function () {
    $text = TranscriptNormalizer::plainText([
        new Segment(1, 'Me', 0, 1, 'hi'),
        new Segment(2, 'Client', 2, 3, 'hello'),
    ]);
    expect($text)->toBe("Me: hi\nClient: hello");
});

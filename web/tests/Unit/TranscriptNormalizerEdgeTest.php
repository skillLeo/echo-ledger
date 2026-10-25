<?php

declare(strict_types=1);

use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use App\Stt\Data\Word;
use App\Stt\TranscriptNormalizer;

function wordAt(string $text, int $start, int $end, ?float $confidence = null): Word
{
    return new Word($text, $start, $end, $confidence);
}

describe('segmentWords', function () {
    it('returns nothing for no words', function () {
        expect(TranscriptNormalizer::segmentWords([], 1, 'Me'))->toBe([]);
    });

    it('makes one segment of one word', function () {
        $segments = TranscriptNormalizer::segmentWords([wordAt('Hello', 100, 500)], 2, 'Client');

        expect($segments)->toHaveCount(1)
            ->and($segments[0])->toMatchObject(['channel' => 2, 'speaker' => 'Client', 'startMs' => 100, 'endMs' => 500, 'text' => 'Hello']);
    });

    it('splits at a gap of exactly the threshold but not just below it', function () {
        $split = TranscriptNormalizer::segmentWords([wordAt('a', 0, 100), wordAt('b', 1100, 1200)], 1, 'Me', gapMs: 1000);
        $joined = TranscriptNormalizer::segmentWords([wordAt('a', 0, 100), wordAt('b', 1099, 1200)], 1, 'Me', gapMs: 1000);

        expect($split)->toHaveCount(2)->and($joined)->toHaveCount(1)->and($joined[0]->text)->toBe('a b');
    });

    it('splits a long run only after sentence-ending punctuation', function () {
        $words = [];
        for ($i = 0; $i < 30; $i++) {
            $words[] = wordAt($i === 14 ? 'there.' : "w{$i}", $i * 1000, $i * 1000 + 900);
        }

        $segments = TranscriptNormalizer::segmentWords($words, 1, 'Me', gapMs: 5000, softMaxMs: 12000);

        expect($segments)->toHaveCount(2)->and($segments[0]->text)->toEndWith('there.')->and($segments[1]->text)->toStartWith('w15');
    });

    it('never splits mid-sentence however long it runs', function () {
        $words = array_map(fn (int $i) => wordAt("w{$i}", $i * 1000, $i * 1000 + 900), range(0, 40));

        expect(TranscriptNormalizer::segmentWords($words, 1, 'Me', gapMs: 5000, softMaxMs: 12000))->toHaveCount(1);
    });

    it('recognises every sentence-ending mark', function (string $mark) {
        $words = [wordAt("end{$mark}", 0, 13000), wordAt('next', 13100, 13500)];

        expect(TranscriptNormalizer::segmentWords($words, 1, 'Me', gapMs: 5000, softMaxMs: 12000))->toHaveCount(2);
    })->with(['.', '!', '?', '…']);

    it('tidies the space a tokeniser leaves before punctuation', function () {
        $segments = TranscriptNormalizer::segmentWords([wordAt('Hello', 0, 100), wordAt(',', 100, 110), wordAt('world', 120, 300), wordAt('!', 300, 310)], 1, 'Me');

        expect($segments[0]->text)->toBe('Hello, world!');
    });

    it('keeps each word with its own timing', function () {
        $segments = TranscriptNormalizer::segmentWords([wordAt('a', 0, 100, 0.9), wordAt('b', 150, 300, 0.5)], 1, 'Me');

        expect($segments[0]->words)->toHaveCount(2)->and($segments[0]->words[1]->confidence)->toBe(0.5);
    });
});

describe('sortByStart', function () {
    it('orders by start then channel and keeps equal segments in their input order', function () {
        $a = new Segment(1, 'Me', 100, 200, 'first');
        $b = new Segment(1, 'Me', 100, 200, 'second');
        $c = new Segment(2, 'Client', 100, 200, 'third');
        $d = new Segment(1, 'Me', 50, 60, 'earliest');

        $texts = array_map(fn (Segment $s) => $s->text, TranscriptNormalizer::sortByStart([$c, $b, $a, $d]));

        expect($texts)->toBe(['earliest', 'second', 'first', 'third']);
    });

    it('handles an empty list', function () {
        expect(TranscriptNormalizer::sortByStart([]))->toBe([]);
    });
});

describe('dedupeBleed', function () {
    it('keeps a Me segment that equals a Client segment which is not near it in time', function () {
        $segments = [
            new Segment(2, 'Client', 0, 2000, 'please send me the quarterly report'),
            new Segment(1, 'Me', 10000, 12000, 'please send me the quarterly report'),
        ];

        expect(TranscriptNormalizer::dedupeBleed($segments))->toHaveCount(2);
    });

    it('treats overlap with a half second of tolerance on either side', function () {
        $client = new Segment(2, 'Client', 1000, 2000, 'the numbers look strong this quarter');
        $inside = new Segment(1, 'Me', 2500, 3500, 'the numbers look strong this quarter');
        $outside = new Segment(1, 'Me', 2501, 3500, 'the numbers look strong this quarter');

        expect(TranscriptNormalizer::dedupeBleed([$client, $inside]))->toHaveCount(1)
            ->and(TranscriptNormalizer::dedupeBleed([$client, $outside]))->toHaveCount(2);
    });

    it('ignores case, punctuation and spacing when comparing', function () {
        $segments = [
            new Segment(2, 'Client', 0, 2000, 'The numbers, honestly, look STRONG this quarter!'),
            new Segment(1, 'Me', 100, 2100, 'the numbers honestly look strong this quarter'),
        ];

        expect(TranscriptNormalizer::dedupeBleed($segments))->toHaveCount(1);
    });

    it('keeps a Me segment that only partly resembles the Client', function () {
        $segments = [
            new Segment(2, 'Client', 0, 3000, 'the numbers look strong this quarter and next'),
            new Segment(1, 'Me', 100, 3100, 'I disagree entirely about the forecast'),
        ];

        expect(TranscriptNormalizer::dedupeBleed($segments))->toHaveCount(2);
    });

    it('leaves Client segments alone, whatever they resemble', function () {
        $segments = [
            new Segment(1, 'Me', 0, 2000, 'the numbers look strong this quarter'),
            new Segment(2, 'Client', 100, 2100, 'the numbers look strong this quarter'),
        ];

        $kept = TranscriptNormalizer::dedupeBleed($segments);

        expect(array_map(fn (Segment $s) => $s->channel, $kept))->toBe([2]);
    });

    it('returns the input untouched when there is no Client channel', function () {
        $segments = [new Segment(1, 'Me', 0, 1000, 'the numbers look strong this quarter'), new Segment(1, 'Me', 0, 1000, 'the numbers look strong this quarter')];

        expect(TranscriptNormalizer::dedupeBleed($segments))->toBe($segments);
    });

    it('honours a stricter or looser threshold', function () {
        $client = new Segment(2, 'Client', 0, 2000, 'the numbers look strong this quarter');
        $me = new Segment(1, 'Me', 0, 2000, 'the numbers look strong this year');

        expect(TranscriptNormalizer::dedupeBleed([$client, $me], 95.0))->toHaveCount(2)
            ->and(TranscriptNormalizer::dedupeBleed([$client, $me], 50.0))->toHaveCount(1);
    });

    it('works on non-latin text', function () {
        $segments = [
            new Segment(2, 'Client', 0, 2000, 'ہم کل ملاقات کریں گے صبح دس بجے'),
            new Segment(1, 'Me', 50, 2050, 'ہم کل ملاقات کریں گے صبح دس بجے'),
        ];

        expect(TranscriptNormalizer::dedupeBleed($segments))->toHaveCount(1);
    });

    it('returns a list with sequential keys', function () {
        $kept = TranscriptNormalizer::dedupeBleed([
            new Segment(2, 'Client', 0, 2000, 'the numbers look strong this quarter'),
            new Segment(1, 'Me', 50, 2050, 'the numbers look strong this quarter'),
            new Segment(1, 'Me', 9000, 9500, 'a different remark entirely'),
        ]);

        expect(array_keys($kept))->toBe([0, 1]);
    });
});

describe('plainText and conversions', function () {
    it('renders nothing for no segments', function () {
        expect(TranscriptNormalizer::plainText([]))->toBe('');
    });

    it('keeps multi-line segment text on one labelled entry', function () {
        expect(TranscriptNormalizer::plainText([new Segment(1, 'Me', 0, 1, 'line one'), new Segment(2, 'Client', 2, 3, 'line two')]))->toBe("Me: line one\nClient: line two");
    });

    it('converts seconds to rounded milliseconds', function (float|int|string|null $seconds, int $ms) {
        expect(TranscriptNormalizer::secondsToMs($seconds))->toBe($ms);
    })->with([
        'integer' => [2, 2000],
        'fraction' => [1.2345, 1235],
        'rounds half up' => [0.0005, 1],
        'string' => ['3.5', 3500],
        'null' => [null, 0],
        'zero' => [0, 0],
        'long call' => [10800.001, 10800001],
    ]);

    it('labels a single channel Speaker and a stereo pair Me and Client', function () {
        expect(TranscriptNormalizer::speakerFor(1, 1))->toBe('Speaker')
            ->and(TranscriptNormalizer::speakerFor(2, 1))->toBe('Speaker')
            ->and(TranscriptNormalizer::speakerFor(1, 0))->toBe('Speaker')
            ->and(TranscriptNormalizer::speakerFor(1, 2))->toBe('Me')
            ->and(TranscriptNormalizer::speakerFor(2, 2))->toBe('Client');
    });
});

describe('transport types', function () {
    it('serialises a segment with every field the clients read', function () {
        $segment = new Segment(2, 'Client', 12840, 15210, 'Hello', [new Word('Hello', 12840, 13000, 0.97)]);

        expect($segment->toArray())->toBe([
            'channel' => 2, 'speaker' => 'Client', 'start_ms' => 12840, 'end_ms' => 15210, 'text' => 'Hello',
            'words' => [['text' => 'Hello', 'start_ms' => 12840, 'end_ms' => 13000, 'confidence' => 0.97]],
        ]);
    });

    it('survives a round trip through arrays', function () {
        $original = new Segment(1, 'Me', 250, 2600, 'Smoke from the north.', [new Word('Smoke', 250, 650, 0.97), new Word('from', 700, 800)]);

        $copy = Segment::fromArray($original->toArray());

        expect($copy->toArray())->toBe($original->toArray());
    });

    it('fills in defaults for a sparse device segment', function () {
        $segment = Segment::fromArray(['channel' => 2, 'start_ms' => 5, 'end_ms' => 9, 'text' => 'hi']);

        expect($segment->speaker)->toBe('Speaker')->and($segment->words)->toBe([]);
    });

    it('coerces numeric strings and ignores malformed words', function () {
        $segment = Segment::fromArray([
            'channel' => '2', 'speaker' => 'Client', 'start_ms' => '100', 'end_ms' => '900', 'text' => 'x',
            'words' => ['not an array', ['text' => 'ok', 'start_ms' => '1', 'end_ms' => '2'], 7, null],
        ]);

        expect($segment->channel)->toBe(2)->and($segment->startMs)->toBe(100)->and($segment->words)->toHaveCount(1)->and($segment->words[0]->text)->toBe('ok');
    });

    it('turns a normalized transcript into the list the database stores', function () {
        $transcript = new NormalizedTranscript('Me: hi', [new Segment(1, 'Me', 0, 1, 'hi')], 'en', ['audio_channels' => 2]);

        expect($transcript->segmentsArray())->toBe([['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'hi', 'words' => []]]);
    });
});

<?php

declare(strict_types=1);

use App\Enums\CompletionMode;
use App\Stt\Data\ProviderCapabilities;
use App\Stt\KeytermPolicy;

/** Capabilities with explicit keyterm limits; everything else is irrelevant to the policy. */
function keytermLimits(int $count, int $chars, int $words, int $rtCount = 100, int $rtChars = 50): ProviderCapabilities
{
    return new ProviderCapabilities(CompletionMode::AsyncWebhook, 'cloud', true, false, [], $count, $chars, $words, $rtCount, $rtChars);
}

describe('sanitize', function () {
    it('keeps the order and the first spelling of duplicates', function () {
        expect(KeytermPolicy::sanitize(['Zeta', 'alpha', 'ALPHA', 'Alpha', 'beta', 'zeta']))->toBe(['Zeta', 'alpha', 'beta']);
    });

    it('treats accented and non-latin text case-insensitively', function () {
        expect(KeytermPolicy::sanitize(['École', 'école', 'ÉCOLE', 'Привет', 'привет']))->toBe(['École', 'Привет']);
    });

    it('removes exactly the characters providers reject', function (string $input, string $expected) {
        expect(KeytermPolicy::sanitize([$input]))->toBe($expected === '' ? [] : [$expected]);
    })->with([
        'angle brackets' => ['a<b>c', 'abc'],
        'braces' => ['a{b}c', 'abc'],
        'square brackets' => ['a[b]c', 'abc'],
        'backslash' => ['a\\b', 'ab'],
        'all of them' => ['<{[\\]}>', ''],
        'quotes and ampersand survive' => ['Acme & Sons "Ltd"', 'Acme & Sons "Ltd"'],
        'apostrophe survives' => ["O'Brien", "O'Brien"],
        'hyphen and dot survive' => ['Jean-Luc P.', 'Jean-Luc P.'],
        'digits survive' => ['Q4 2026', 'Q4 2026'],
    ]);

    it('collapses every run of white space into one space', function () {
        expect(KeytermPolicy::sanitize(["a\tb", "c\n\nd", '  e   f  ', "g\u{00A0}h"]))->toBe(['a b', 'c d', 'e f', 'g h']);
    });

    it('drops empty and whitespace-only terms but keeps a lone zero', function () {
        expect(KeytermPolicy::sanitize(['', '   ', "\t", '0', 0, '00']))->toBe(['0', '00']);
    });

    it('accepts any iterable and non-string scalars', function () {
        expect(KeytermPolicy::sanitize((fn () => yield from ['a', 'b'])()))->toBe(['a', 'b']);
        expect(KeytermPolicy::sanitize([12, 3.5, true]))->toBe(['12', '3.5', '1']);
    });

    it('returns an empty list for nothing', function () {
        expect(KeytermPolicy::sanitize([]))->toBe([]);
    });

    it('returns a list with sequential keys', function () {
        $result = KeytermPolicy::sanitize([5 => 'a', 9 => 'b']);

        expect(array_keys($result))->toBe([0, 1]);
    });
});

describe('forProvider', function () {
    it('keeps terms exactly at the character limit and drops those one over', function () {
        $caps = keytermLimits(10, 10, 6);

        expect(KeytermPolicy::forProvider([str_repeat('a', 10), str_repeat('b', 11)], $caps, false))->toBe([str_repeat('a', 10)]);
    });

    it('counts characters, not bytes', function () {
        $caps = keytermLimits(10, 4, 6);

        expect(KeytermPolicy::forProvider(['ééée', 'ééééé'], $caps, false))->toBe(['ééée']);
    });

    it('keeps terms exactly at the word limit and drops those one over', function () {
        $caps = keytermLimits(10, 100, 3);

        expect(KeytermPolicy::forProvider(['one two three', 'one two three four'], $caps, false))->toBe(['one two three']);
    });

    it('lets over-long terms fall away before the count limit applies', function () {
        $caps = keytermLimits(2, 5, 6);

        $result = KeytermPolicy::forProvider(['toolongterm', 'ok1', 'waytoolongtoo', 'ok2', 'ok3'], $caps, false);

        expect($result)->toBe(['ok1', 'ok2']);
    });

    it('uses the realtime limits when asked and the batch limits otherwise', function () {
        $caps = keytermLimits(5, 50, 6, 2, 4);
        $terms = ['aaa', 'bbb', 'ccc', 'long term'];

        expect(KeytermPolicy::forProvider($terms, $caps, true))->toBe(['aaa', 'bbb'])
            ->and(KeytermPolicy::forProvider($terms, $caps, false))->toBe(['aaa', 'bbb', 'ccc', 'long term']);
    });

    it('returns nothing when the provider takes no keyterms', function () {
        expect(KeytermPolicy::forProvider(['a', 'b'], keytermLimits(0, 0, 0, 0, 0), false))->toBe([]);
        expect(KeytermPolicy::forProvider(['a', 'b'], keytermLimits(-5, 50, 6), false))->toBe([]);
    });

    it('sanitizes before it measures', function () {
        $caps = keytermLimits(10, 5, 6);

        expect(KeytermPolicy::forProvider(['a<b>c', '  abc  ', 'ABC'], $caps, false))->toBe(['abc']);
    });

    it('never returns more than the limit however many are given', function () {
        $terms = array_map(fn (int $i) => "term{$i}", range(1, 5000));

        expect(KeytermPolicy::forProvider($terms, keytermLimits(100, 50, 6), false))->toHaveCount(100);
    });

    it('applies the documented limits of the real providers', function (int $count, int $chars, int $words, bool $realtime, int $expectedCount) {
        $terms = array_map(fn (int $i) => "t{$i}", range(1, 1200));

        expect(KeytermPolicy::forProvider($terms, keytermLimits($count, $chars, $words, $count, $chars), $realtime))->toHaveCount($expectedCount);
    })->with([
        'AssemblyAI streaming' => [100, 50, 6, true, 100],
        'ElevenLabs batch' => [1000, 49, 5, false, 1000],
        'ElevenLabs realtime' => [50, 20, 5, true, 50],
        'Azure' => [500, 50, 6, false, 500],
        'whisper.cpp prompt' => [60, 50, 6, false, 60],
    ]);
});

describe('validate', function () {
    it('accepts the boundary values', function () {
        expect(KeytermPolicy::validate(str_repeat('a', 50)))->toBeNull()
            ->and(KeytermPolicy::validate('one two three four five'))->toBeNull()
            ->and(KeytermPolicy::validate('x'))->toBeNull();
    });

    it('rejects one past each boundary with a message that names the limit', function () {
        expect(KeytermPolicy::validate(str_repeat('a', 51)))->toContain('50')
            ->and(KeytermPolicy::validate('one two three four five six'))->toContain('5');
    });

    it('rejects terms that are empty once cleaned', function (string $term) {
        expect(KeytermPolicy::validate($term))->toContain('empty');
    })->with(['', '   ', '[]', '<>{}\\']);

    it('measures the cleaned term, not the raw input', function () {
        expect(KeytermPolicy::validate('  '.str_repeat('a', 50).'  '))->toBeNull()
            ->and(KeytermPolicy::validate(str_repeat('a', 25).'<<<<<<<'.str_repeat('a', 25)))->toBeNull();
    });

    it('counts characters, not bytes', function () {
        expect(KeytermPolicy::validate(str_repeat('é', 50)))->toBeNull();
    });

    it('is at least as strict as every provider\'s per-term limit', function () {
        // Anything the admin may save must be usable by the strictest batch provider (49 chars on ElevenLabs).
        expect(KeytermPolicy::MAX_CHARS)->toBeLessThanOrEqual(50)->and(KeytermPolicy::MAX_WORDS)->toBeLessThanOrEqual(6);
    });
});

<?php

declare(strict_types=1);

// W-213 review: json_decode keeps the last of two equal keys without a word, so lang/ur.json is read as text: every
// key once, and nothing in the file that is not a key and its line.

/**
 * The keys of a flat JSON object as they are written, in order (duplicates kept).
 *
 * @return list<string>
 */
function urduJsonKeys(string $raw): array
{
    preg_match_all('/^\s*("(?:[^"\\\\]|\\\\.)*")\s*:\s*"(?:[^"\\\\]|\\\\.)*"\s*,?\s*$/mu', $raw, $m);

    return array_map(fn (string $key): string => (string) json_decode($key), $m[1]);
}

it('has every key of lang/ur.json once', function () {
    $raw = (string) file_get_contents(__DIR__.'/../../lang/ur.json');
    $keys = urduJsonKeys($raw);
    $twice = array_keys(array_filter(array_count_values($keys), fn (int $n): bool => $n > 1));

    expect($twice)->toBe([], 'twice in lang/ur.json: '.implode(', ', $twice))
        ->and(count($keys))->toBe(count((array) json_decode($raw, true)), 'a line of lang/ur.json is not one "key": "value"');
});

it('finds a key written twice', function () {
    expect(urduJsonKeys("{\n    \"Open\": \"a\",\n    \"Open\": \"b\"\n}\n"))->toBe(['Open', 'Open']);
});

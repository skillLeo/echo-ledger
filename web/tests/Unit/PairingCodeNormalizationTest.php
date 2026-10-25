<?php

declare(strict_types=1);

use App\Services\PairingService;

it('removes hyphens and any kind of white space', function (string $typed, string $expected) {
    expect(PairingService::normalize($typed))->toBe($expected);
})->with([
    'display form' => ['7KQ2-M9XD', '7KQ2M9XD'],
    'no separator' => ['7KQ2M9XD', '7KQ2M9XD'],
    'spaces around the hyphen' => ['7KQ2 - M9XD', '7KQ2M9XD'],
    'leading and trailing space' => ['  7KQ2M9XD  ', '7KQ2M9XD'],
    'tab and newline' => ["7KQ2\t\n-M9XD", '7KQ2M9XD'],
    'several hyphens' => ['7K--Q2-M9-XD', '7KQ2M9XD'],
    'only separators' => [' - - ', ''],
    'empty' => ['', ''],
]);

it('upper-cases', function () {
    expect(PairingService::normalize('abcd-efgh'))->toBe('ABCDEFGH');
});

it('maps the Crockford look-alikes and nothing else', function (string $typed, string $expected) {
    expect(PairingService::normalize($typed))->toBe($expected);
})->with([
    'capital O is zero' => ['O', '0'],
    'lower o is zero' => ['o', '0'],
    'capital I is one' => ['I', '1'],
    'lower i is one' => ['i', '1'],
    'capital L is one' => ['L', '1'],
    'lower l is one' => ['l', '1'],
    'digits stay' => ['0123456789', '0123456789'],
    'U is not remapped' => ['U', 'U'],
    'a mixed code' => ['aOb1-cLdI', 'A0B1C1D1'],
]);

it('is idempotent', function (string $typed) {
    $once = PairingService::normalize($typed);

    expect(PairingService::normalize($once))->toBe($once);
})->with(['7KQ2-M9XD', 'abcd-0123', 'IL1O0-ioli']);

it('gives every spelling of one code the same hash', function () {
    $hashes = array_map(fn (string $typed) => hash('sha256', PairingService::normalize($typed)), ['7KQ2-M9XD', '7kq2m9xd', ' 7KQ2 M9XD ']);

    expect(array_unique($hashes))->toHaveCount(1);
});

it('keeps different codes apart', function () {
    expect(PairingService::normalize('7KQ2-M9XD'))->not->toBe(PairingService::normalize('7KQ2-M9XE'));
});

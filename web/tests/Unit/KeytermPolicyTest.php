<?php

declare(strict_types=1);

use App\Enums\CompletionMode;
use App\Stt\Data\ProviderCapabilities;
use App\Stt\KeytermPolicy;

function caps(int $max, int $chars, int $words, int $rtMax, int $rtChars): ProviderCapabilities
{
    return new ProviderCapabilities(CompletionMode::AsyncWebhook, 'cloud', true, false, [], $max, $chars, $words, $rtMax, $rtChars);
}

it('trims, collapses whitespace and strips characters providers reject', function () {
    expect(KeytermPolicy::sanitize(['  Acme   Holdings ', 'Fa<r>ah{ }Khan']))
        ->toBe(['Acme Holdings', 'Farah Khan']);
});

it('dedupes case-insensitively and drops blanks', function () {
    expect(KeytermPolicy::sanitize(['EchoLedger', 'echoledger', '   ', '[]']))
        ->toBe(['EchoLedger']);
});

it('applies per-provider count and length caps', function () {
    $terms = ['one', 'two', 'three', 'this term is definitely longer than ten chars total xyz'];
    $limited = KeytermPolicy::forProvider($terms, caps(2, 50, 5, 10, 20), realtime: false);
    expect($limited)->toBe(['one', 'two']);

    // Realtime uses the realtime caps (here: 20 chars) and filters the long one out.
    $realtime = KeytermPolicy::forProvider(['short', str_repeat('x', 40)], caps(100, 50, 5, 100, 20), realtime: true);
    expect($realtime)->toBe(['short']);
});

it('validates admin-entered terms with helpful messages', function () {
    expect(KeytermPolicy::validate('Acme'))->toBeNull();
    expect(KeytermPolicy::validate('   '))->toContain('empty');
    expect(KeytermPolicy::validate(str_repeat('a', 60)))->toContain('characters');
    expect(KeytermPolicy::validate('one two three four five six'))->toContain('words');
});

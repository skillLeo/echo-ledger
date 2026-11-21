<?php

declare(strict_types=1);

use App\Filament\Support\ConsoleDates;
use Illuminate\Support\Carbon;

// W-212: one way to write the console's dates. English keeps its formats; Urdu has Urdu month names and the digits 0-9.

it('keeps the English formats', function () {
    $at = Carbon::parse('2026-11-17 09:05:00', 'UTC');

    expect(ConsoleDates::day($at, 'en'))->toBe('Nov 17')
        ->and(ConsoleDates::date($at, 'en'))->toBe('Nov 17, 2026')
        ->and(ConsoleDates::dateTime($at, 'g:i A', 'en'))->toBe('Nov 17, 2026 · 9:05 AM')
        ->and(ConsoleDates::time($at, 'H:i', 'en'))->toBe('09:05')
        ->and(ConsoleDates::month('2026-11', 'en'))->toBe('November 2026')
        ->and(ConsoleDates::shortMonth($at, 'en'))->toBe('Nov 2026')
        ->and(ConsoleDates::format($at, 'M j, Y · g:i A', 'j F Y', 'en'))->toBe('Nov 17, 2026 · 9:05 AM');
});

it('writes Urdu month names with the digits 0-9', function () {
    $at = Carbon::parse('2026-10-20 21:30:00', 'UTC');

    expect(ConsoleDates::day($at, 'ur'))->toBe('20 اکتوبر')
        ->and(ConsoleDates::date($at, 'ur'))->toBe('20 اکتوبر 2026')
        ->and(ConsoleDates::month('2026-10', 'ur'))->toBe('اکتوبر 2026')
        ->and(ConsoleDates::shortMonth('2026-02', 'ur'))->toBe('فروری 2026')
        ->and(ConsoleDates::time($at, 'H:i', 'ur'))->toBe('21:30')
        ->and(ConsoleDates::dateTime($at, 'g:i A', 'ur'))->toStartWith('20 اکتوبر 2026 · 9:30 ')
        ->and(ConsoleDates::dateTime($at, 'g:i A', 'ur'))->not->toMatch('/[A-Za-z٠-٩۰-۹]/u');
});

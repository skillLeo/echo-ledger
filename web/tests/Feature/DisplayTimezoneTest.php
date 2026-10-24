<?php

declare(strict_types=1);

use App\Services\SettingsRepository;
use Filament\Support\Facades\FilamentTimezone;

it('defaults to UTC', function () {
    expect(app(SettingsRepository::class)->displayTimezone())->toBe('UTC');
});

it('uses the configured IANA zone, and the console reads it lazily', function () {
    $settings = app(SettingsRepository::class);
    $settings->set('display.timezone', 'Asia/Karachi');

    expect($settings->displayTimezone())->toBe('Asia/Karachi');
    expect(FilamentTimezone::get())->toBe('Asia/Karachi');
});

it('falls back to UTC for an unrecognised zone instead of breaking every page', function () {
    $settings = app(SettingsRepository::class);
    $settings->set('display.timezone', 'Mars/Phobos');

    expect($settings->displayTimezone())->toBe('UTC');
});

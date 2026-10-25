<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Setting;
use App\Services\SettingsRepository;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
});

describe('which zones are accepted', function () {
    it('uses a valid IANA zone as given', function (string $zone) {
        $settings = app(SettingsRepository::class);
        $settings->set('display.timezone', $zone);

        expect($settings->displayTimezone())->toBe($zone)->and(FilamentTimezone::get())->toBe($zone);
    })->with(['UTC', 'Asia/Karachi', 'America/New_York', 'Europe/London', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Asia/Kolkata', 'Australia/Lord_Howe', 'America/Argentina/Buenos_Aires']);

    it('falls back to UTC for anything it cannot use', function (mixed $stored) {
        $settings = app(SettingsRepository::class);
        $settings->set('display.timezone', $stored);

        expect($settings->displayTimezone())->toBe('UTC');
    })->with([
        'unknown zone' => ['Mars/Phobos'],
        'empty string' => [''],
        'null' => [null],
        'number' => [5],
        'legacy alias outside the standard list' => ['Etc/GMT+5'],
        'wrong case' => ['asia/karachi'],
        'abbreviation' => ['PKT'],
        'numeric offset' => ['+05:00'],
        'surrounding space' => [' Asia/Karachi '],
        'markup' => ['<script>alert(1)</script>'],
    ]);

    it('does not crash the console when a stored value is not text at all', function () {
        $settings = app(SettingsRepository::class);
        $settings->set('display.timezone', ['Asia/Karachi']);

        expect($settings->displayTimezone())->toBe('UTC');
    });

    it('defaults to UTC when the setting row does not exist', function () {
        Setting::query()->where('key', 'display.timezone')->delete();

        expect(app(SettingsRepository::class)->displayTimezone())->toBe('UTC');
    });

    it('reads a changed zone on the next resolution without a restart', function () {
        $settings = app(SettingsRepository::class);
        $settings->set('display.timezone', 'Asia/Karachi');
        expect(FilamentTimezone::get())->toBe('Asia/Karachi');

        $settings->set('display.timezone', 'America/New_York');

        expect(FilamentTimezone::get())->toBe('America/New_York');
    });

    it('stores the zone as plain JSON text in the settings table', function () {
        app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi');

        expect(json_decode((string) DB::table('settings')->where('key', 'display.timezone')->value('value'), true))->toBe('Asia/Karachi');
    });
});

describe('device API times', function () {
    it('stay in UTC whatever zone the console displays', function (string $zone) {
        app(SettingsRepository::class)->set('display.timezone', $zone);
        $this->createSession(Fixtures::audio(3000), ['started_at' => '2026-10-24T09:00:00+00:00', 'ended_at' => '2026-10-24T09:05:00+00:00'])->assertCreated();

        $item = $this->getJson('/api/library')->json('data.0');
        $config = $this->getJson('/api/config')->json();

        expect($item['started_at'])->toEndWith('+00:00')->toBe('2026-10-24T09:00:00+00:00')
            ->and($item['ended_at'])->toBe('2026-10-24T09:05:00+00:00')
            ->and($item['updated_at'])->toEndWith('+00:00')
            ->and($config['server_time'])->toEndWith('+00:00');
    })->with(['Asia/Karachi', 'America/New_York', 'Pacific/Kiritimati', 'Pacific/Pago_Pago']);

    it('use one fixed application zone', function () {
        expect(config('app.timezone'))->toBe('UTC');
    });

    it('keep their meaning across a daylight saving change in the display zone', function () {
        app(SettingsRepository::class)->set('display.timezone', 'America/New_York');
        // 2026-03-08 07:30 UTC is 02:30 EST: the wall clock instant that does not exist in New York that morning.
        $this->createSession(Fixtures::audio(3000), ['started_at' => '2026-03-08T07:30:00Z', 'ended_at' => '2026-03-08T07:45:00Z'])->assertCreated();

        $item = $this->getJson('/api/library')->json('data.0');

        expect($item['started_at'])->toBe('2026-03-08T07:30:00+00:00')->and($item['ended_at'])->toBe('2026-03-08T07:45:00+00:00');
    });

    it('are the instants the device sent, to the second', function () {
        $this->createSession(Fixtures::audio(3000), ['started_at' => '2026-12-31T23:59:59Z', 'ended_at' => '2027-01-01T00:00:01Z'])->assertCreated();

        $item = $this->getJson('/api/library')->json('data.0');

        expect($item['started_at'])->toBe('2026-12-31T23:59:59+00:00')->and($item['ended_at'])->toBe('2027-01-01T00:00:01+00:00');
    });
});

describe('how the console renders them', function () {
    it('converts a stored UTC instant into the display zone', function (string $zone, string $expected) {
        app(SettingsRepository::class)->set('display.timezone', $zone);
        $session = CaptureSession::factory()->create(['started_at' => '2026-10-24 09:00:00']);

        $shown = $session->refresh()->started_at->copy()->setTimezone(FilamentTimezone::get());

        expect($shown->format('Y-m-d H:i T'))->toBe($expected);
    })->with([
        ['UTC', '2026-10-24 09:00 UTC'],
        ['Asia/Karachi', '2026-10-24 14:00 PKT'],
        ['America/New_York', '2026-10-24 05:00 EDT'],
        ['Pacific/Kiritimati', '2026-10-24 23:00 +14'],
    ]);

    it('shows winter and summer instants of one zone with different offsets', function () {
        app(SettingsRepository::class)->set('display.timezone', 'America/New_York');
        $zone = FilamentTimezone::get();
        $summer = CaptureSession::factory()->create(['started_at' => '2026-07-01 12:00:00'])->refresh()->started_at->copy()->setTimezone($zone);
        $winter = CaptureSession::factory()->create(['started_at' => '2026-01-01 12:00:00'])->refresh()->started_at->copy()->setTimezone($zone);

        expect($summer->format('H:i'))->toBe('08:00')->and($winter->format('H:i'))->toBe('07:00');
    });
});

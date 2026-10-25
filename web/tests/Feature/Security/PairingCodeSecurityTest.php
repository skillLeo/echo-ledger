<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

function secRegister(string $code, string $ip = '198.51.100.1'): TestResponse
{
    return test()->api()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Pixel', 'platform' => 'android']);
}

it('issues codes of eight unambiguous characters displayed as two groups of four', function () {
    $pairing = app(PairingService::class);
    $owner = Fx::user();

    for ($i = 0; $i < 200; $i++) {
        expect($pairing->createCode($owner))->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/');
    }
});

it('draws codes from the whole alphabet without an obvious bias or repeats', function () {
    $pairing = app(PairingService::class);
    $owner = Fx::user();
    $codes = [];
    $counts = [];

    for ($i = 0; $i < 1500; $i++) {
        $code = str_replace('-', '', $pairing->createCode($owner));
        $codes[] = $code;
        foreach (str_split($code) as $char) {
            $counts[$char] = ($counts[$char] ?? 0) + 1;
        }
    }

    expect(count(array_unique($codes)))->toBe(1500)->and(count($counts))->toBe(32);
    $expected = 1500 * 8 / 32;
    foreach ($counts as $char => $seen) {
        expect(abs($seen - $expected) / $expected)->toBeLessThan(0.25, "character {$char} appeared {$seen} times");
    }
});

it('stores only a hash of the normalised code and marks it used on redemption', function () {
    $owner = Fx::user();
    $display = app(PairingService::class)->createCode($owner);
    $normalised = str_replace('-', '', $display);

    secRegister($display)->assertCreated();

    $row = DevicePairingCode::query()->firstOrFail();
    expect($row->code_hash)->toBe(hash('sha256', $normalised))
        ->and($row->used_at)->not->toBeNull()
        ->and($row->device_id)->not->toBeNull()
        ->and(json_encode(DB::table('device_pairing_codes')->get()))->not->toContain($normalised)->not->toContain($display);
});

it('redeems a code exactly once', function () {
    $display = app(PairingService::class)->createCode(Fx::user());

    secRegister($display, '198.51.100.2')->assertCreated();
    secRegister($display, '198.51.100.3')->assertStatus(422);
    secRegister($display, '198.51.100.4')->assertStatus(422);

    expect(Device::query()->count())->toBe(1);
});

it('accepts the code in any case, with or without the hyphen, and with Crockford look-alikes', function () {
    $pairing = app(PairingService::class);
    $owner = Fx::user();
    $ip = 10;

    foreach ([fn (string $c) => strtolower($c), fn (string $c) => str_replace('-', '', $c), fn (string $c) => ' '.$c.' ', fn (string $c) => str_replace('-', ' ', $c)] as $mutate) {
        $display = $pairing->createCode($owner);
        secRegister($mutate($display), '198.51.100.'.(++$ip))->assertCreated();
    }
    expect(Device::query()->count())->toBe(4);
});

it('expires a code after ten minutes', function () {
    $pairing = app(PairingService::class);
    $owner = Fx::user();
    $beforeDeadline = $pairing->createCode($owner);
    $afterDeadline = $pairing->createCode($owner);

    $this->travelTo(now()->addSeconds(599));
    secRegister($beforeDeadline, '198.51.100.40')->assertCreated();

    $this->travelTo(now()->addSeconds(3));
    secRegister($afterDeadline, '198.51.100.41')->assertStatus(422);
});

it('gives the same answer for unknown, used and expired codes so they cannot be told apart', function () {
    $pairing = app(PairingService::class);
    $used = $pairing->createCode(Fx::user());
    secRegister($used, '198.51.100.50')->assertCreated();
    $expired = $pairing->createCode(Fx::user());
    $this->travel(11)->minutes();

    $messages = [
        secRegister('ZZZZ-ZZZZ', '198.51.100.51')->assertStatus(422)->json(),
        secRegister($used, '198.51.100.52')->assertStatus(422)->json(),
        secRegister($expired, '198.51.100.53')->assertStatus(422)->json(),
    ];

    expect($messages[0])->toBe($messages[1])->and($messages[1])->toBe($messages[2]);
});

it('binds the new device to the user who generated the code', function () {
    $pairing = app(PairingService::class);
    $first = Fx::user();
    $second = Fx::user();
    $codeA = $pairing->createCode($first);
    $codeB = $pairing->createCode($second);

    $deviceB = secRegister($codeB, '198.51.100.60')->assertCreated()->json('device.id');
    $deviceA = secRegister($codeA, '198.51.100.61')->assertCreated()->json('device.id');

    expect(Device::query()->find($deviceA)->user_id)->toBe($first->id)
        ->and(Device::query()->find($deviceB)->user_id)->toBe($second->id);
});

it('rejects pairing codes of the wrong shape without touching the database', function (mixed $code) {
    $response = test()->api()->withServerVariables(['REMOTE_ADDR' => '198.51.100.70'])
        ->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Pixel', 'platform' => 'android']);

    expect($response->getStatusCode())->toBe(422);
    expect(Device::query()->count())->toBe(0);
})->with([[null], [''], [12345678], [true], [['AAAA-AAAA']], [str_repeat('A', 17)], ["AAAA-AAAA\0"], ["' OR 1=1 --"]]);

it('builds the pairing QR from the configured URL and never from the request host', function () {
    $pairing = app(PairingService::class);
    $payload = json_decode($pairing->qrPayload('ABCD-EFGH'), true);

    expect($payload)->toBe(['v' => 1, 'url' => rtrim((string) config('app.url'), '/'), 'code' => 'ABCDEFGH', 'pins' => []]);
    $this->withServerVariables(['HTTP_HOST' => 'attacker.example'])->get('/admin/login');
    expect(json_decode($pairing->qrPayload('ABCD-EFGH'), true)['url'])->not->toContain('attacker');
});

it('returns the pin set with the pairing response and never an admin credential', function () {
    $pins = ['sha256/'.str_repeat('A', 43).'=', 'sha256/'.str_repeat('B', 43).'='];
    app(SettingsRepository::class)->set('app.pins', $pins);
    $display = app(PairingService::class)->createCode(Fx::user());

    $response = secRegister($display, '198.51.100.80')->assertCreated();

    expect($response->json('config.pins'))->toBe($pins)
        ->and($response->getContent())->not->toContain('password')->not->toContain('email')->not->toContain('is_super_admin');
});

it('prunes expired codes after a day', function () {
    app(PairingService::class)->createCode(Fx::user());
    $this->travel(2)->days();

    Artisan::call('model:prune', ['--model' => [DevicePairingCode::class]]);

    expect(DevicePairingCode::query()->count())->toBe(0);
});

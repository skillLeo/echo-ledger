<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/** @return array<string, mixed> */
function registrationBody(string $code, array $overrides = []): array
{
    return array_merge(['pairing_code' => $code, 'name' => 'Work laptop', 'platform' => 'windows', 'app_version' => '1.2.3', 'os_version' => '11'], $overrides);
}

/** Store a pairing code whose normalized form is $raw, valid for $minutes from now. */
function plantCode(User $user, string $raw, int $minutes = 10): void
{
    DevicePairingCode::query()->create([
        'user_id' => $user->id,
        'code_hash' => hash('sha256', $raw),
        'expires_at' => now()->addMinutes($minutes),
    ]);
}

describe('code generation', function () {
    it('issues codes as two Crockford base32 groups of four', function () {
        $user = User::factory()->create();
        $service = app(PairingService::class);

        foreach (range(1, 60) as $_) {
            expect($service->createCode($user))->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/');
        }
    });

    it('never emits the ambiguous letters I, L, O or U', function () {
        $user = User::factory()->create();
        $service = app(PairingService::class);
        $all = '';
        foreach (range(1, 200) as $_) {
            $all .= $service->createCode($user);
        }

        expect($all)->not->toMatch('/[ILOU]/');
    });

    it('generates distinct codes', function () {
        $user = User::factory()->create();
        $service = app(PairingService::class);
        $codes = array_map(fn () => $service->createCode($user), range(1, 100));

        expect(array_unique($codes))->toHaveCount(100);
    });

    it('stores only the SHA-256 of the normalized code and a ten minute expiry', function () {
        $this->freezeTime();
        $user = User::factory()->create();
        $display = app(PairingService::class)->createCode($user);
        $raw = str_replace('-', '', $display);

        $row = DevicePairingCode::query()->sole();
        expect($row->code_hash)->toBe(hash('sha256', $raw))
            ->and($row->code_hash)->not->toContain($raw)
            ->and($row->used_at)->toBeNull()
            ->and($row->expires_at->getTimestamp())->toBe(now()->addMinutes(10)->getTimestamp())
            ->and($row->user_id)->toBe($user->id);
    });

    it('builds the QR payload documented in API.md', function () {
        config(['app.url' => 'https://ledger.example.com/']);
        app(SettingsRepository::class)->set('app.pins', ['sha256/AAAA=', 'sha256/BBBB=']);

        $payload = json_decode(app(PairingService::class)->qrPayload('7kq2-m9xd'), true, flags: JSON_THROW_ON_ERROR);

        expect($payload)->toBe([
            'v' => 1,
            'url' => 'https://ledger.example.com',
            'code' => '7KQ2M9XD',
            'pins' => ['sha256/AAAA=', 'sha256/BBBB='],
        ]);
    });
});

describe('redeeming a code', function () {
    it('registers a device and returns the documented 201 body', function () {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);

        $response = $this->postJson('/api/devices/register', registrationBody($code, ['platform' => 'android', 'name' => 'S25 Ultra']));

        $response->assertCreated()
            ->assertJsonStructure([
                'device' => ['id', 'name', 'platform', 'source_device', 'created_at'],
                'token', 'token_expires_at',
                'config' => ['device', 'server_time', 'retention_days', 'upload', 'providers', 'captions', 'pins'],
            ])
            ->assertJsonPath('device.name', 'S25 Ultra')
            ->assertJsonPath('device.platform', 'android')
            ->assertJsonPath('device.source_device', 'phone');
        expect($response->json('device.id'))->toMatch(Fixtures::ULID)
            ->and($response->json('token'))->toMatch('/^\d+\|eld_[A-Za-z0-9]+$/')
            ->and($response->json('token_expires_at'))->toMatch(Fixtures::ISO_8601);
    });

    it('binds the new device to the user that created the code and records its details', function () {
        $owner = User::factory()->create();
        User::factory()->create();
        $code = app(PairingService::class)->createCode($owner);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/devices/register', registrationBody($code))->assertCreated();

        $device = Device::query()->sole();
        expect($device->user_id)->toBe($owner->id)
            ->and($device->name)->toBe('Work laptop')
            ->and($device->app_version)->toBe('1.2.3')
            ->and($device->os_version)->toBe('11')
            ->and($device->last_ip)->toBe('203.0.113.7')
            ->and($device->last_seen_at)->not->toBeNull();
    });

    it('marks the code used and links it to the device', function () {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);

        $deviceId = $this->postJson('/api/devices/register', registrationBody($code))->assertCreated()->json('device.id');

        $row = DevicePairingCode::query()->sole();
        expect($row->used_at)->not->toBeNull()->and($row->device_id)->toBe($deviceId);
    });

    it('issues a token with exactly the standard abilities and a 365 day expiry', function () {
        $this->freezeTime();
        $user = User::factory()->create();
        $response = $this->postJson('/api/devices/register', registrationBody(app(PairingService::class)->createCode($user)))->assertCreated();

        $token = PersonalAccessToken::query()->sole();
        expect($token->abilities)->toEqualCanonicalizing(Device::ABILITIES)
            ->and($token->tokenable_type)->toBe('device')
            ->and($token->tokenable_id)->toBe($response->json('device.id'))
            ->and($token->expires_at->getTimestamp())->toBe(now()->addDays(365)->getTimestamp());
        expect(strtotime($response->json('token_expires_at')))->toBe(now()->addDays(365)->getTimestamp());
    });

    it('stores the token hashed, never in clear', function () {
        $user = User::factory()->create();
        $response = $this->postJson('/api/devices/register', registrationBody(app(PairingService::class)->createCode($user)))->assertCreated();
        $plain = explode('|', $response->json('token'), 2)[1];

        $stored = PersonalAccessToken::query()->sole()->token;
        expect($stored)->toBe(hash('sha256', $plain))->and($stored)->not->toContain($plain);
    });

    it('returns a token that works on the next request', function () {
        $user = User::factory()->create();
        $token = $this->postJson('/api/devices/register', registrationBody(app(PairingService::class)->createCode($user)))->json('token');

        $this->asToken($token)->getJson('/api/config')->assertOk();
    });

    it('returns the same configuration body as GET /api/config', function () {
        $user = User::factory()->create();
        $register = $this->postJson('/api/devices/register', registrationBody(app(PairingService::class)->createCode($user)))->assertCreated();

        $config = $this->asToken($register->json('token'))->getJson('/api/config')->assertOk()->json();
        $embedded = $register->json('config');
        unset($config['server_time'], $embedded['server_time']);

        expect($embedded)->toBe($config);
    });

    it('derives the source device from the platform and ignores anything the client claims', function (string $platform, string $expected) {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);

        $response = $this->postJson('/api/devices/register', registrationBody($code, ['platform' => $platform, 'source_device' => 'web']));

        $response->assertCreated()->assertJsonPath('device.source_device', $expected)->assertJsonPath('config.device.source_device', $expected);
    })->with([
        ['android', 'phone'],
        ['ios', 'phone'],
        ['windows', 'desktop'],
        ['macos', 'desktop'],
        ['linux', 'desktop'],
    ]);
});

describe('code formats', function () {
    it('ignores hyphens, case and surrounding whitespace', function (string $typed) {
        plantCode($user = User::factory()->create(), '7KQ2M9XD');

        $this->postJson('/api/devices/register', registrationBody($typed))->assertCreated();

        expect(Device::query()->where('user_id', $user->id)->count())->toBe(1);
    })->with([
        'display form' => '7KQ2-M9XD',
        'no hyphen' => '7KQ2M9XD',
        'lower case' => '7kq2-m9xd',
        'mixed case with spaces' => ' 7kQ2 - m9Xd ',
        'tabs' => "7KQ2\t-M9XD",
    ]);

    it('maps the Crockford look-alikes O, I and L to 0, 1 and 1', function () {
        plantCode(User::factory()->create(), 'A0B1C1D0');

        $this->postJson('/api/devices/register', registrationBody('aOb1-cLdO'))->assertCreated();
        expect(PairingService::normalize('aOb1-cLdO'))->toBe('A0B1C1D0');
    });

    it('is not fooled by the hash being sent instead of the code', function () {
        plantCode(User::factory()->create(), '7KQ2M9XD');

        $this->postJson('/api/devices/register', registrationBody(hash('sha256', '7KQ2M9XD')))
            ->assertStatus(422)->assertJsonValidationErrors(['pairing_code']);
    });
});

describe('rejected codes', function () {
    it('rejects a code that does not exist', function () {
        $this->postJson('/api/devices/register', registrationBody('ZZZZ-ZZZZ'))
            ->assertStatus(422)->assertJsonValidationErrors(['pairing_code']);
        expect(Device::query()->count())->toBe(0)->and(PersonalAccessToken::query()->count())->toBe(0);
    });

    it('accepts a code until it is ten minutes old and rejects it afterwards', function () {
        $this->freezeTime();
        $user = User::factory()->create();
        $service = app(PairingService::class);
        $fresh = $service->createCode($user);
        $stale = $service->createCode($user);

        $this->travel(9)->minutes();
        $this->travel(59)->seconds();
        $this->postJson('/api/devices/register', registrationBody($fresh))->assertCreated();

        $this->travel(2)->seconds(); // the second code is now 10 minutes and 1 second old
        $this->postJson('/api/devices/register', registrationBody($stale))
            ->assertStatus(422)->assertJsonValidationErrors(['pairing_code']);
    });

    it('rejects an expired code without creating a device or token', function () {
        plantCode(User::factory()->create(), 'EXPIRED1', minutes: -1);

        $this->postJson('/api/devices/register', registrationBody('EXPIRED1'))->assertStatus(422);

        expect(Device::query()->count())->toBe(0)->and(PersonalAccessToken::query()->count())->toBe(0);
    });

    it('is single use: a second device cannot redeem the same code', function () {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);

        $this->postJson('/api/devices/register', registrationBody($code, ['name' => 'First']))->assertCreated();
        $this->postJson('/api/devices/register', registrationBody($code, ['name' => 'Second']))
            ->assertStatus(422)->assertJsonValidationErrors(['pairing_code']);

        expect(Device::query()->pluck('name')->all())->toBe(['First'])->and(PersonalAccessToken::query()->count())->toBe(1);
    });

    it('gives the same error for unknown, expired and used codes so none can be told apart', function () {
        $user = User::factory()->create();
        plantCode($user, 'EXPIRED2', minutes: -5);
        $used = app(PairingService::class)->createCode($user);
        $this->postJson('/api/devices/register', registrationBody($used))->assertCreated();

        $messages = array_map(
            fn (string $code) => $this->postJson('/api/devices/register', registrationBody($code))->assertStatus(422)->json('errors.pairing_code.0'),
            ['NEVERSET', 'EXPIRED2', $used],
        );

        expect(array_unique($messages))->toHaveCount(1);
    });

    it('does not burn a valid code when a wrong guess is made first', function () {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);

        $this->postJson('/api/devices/register', registrationBody('WRONG-GUESS'))->assertStatus(422);
        $this->postJson('/api/devices/register', registrationBody($code))->assertCreated();
    });

    it('does not keep a code usable after its owner is deleted', function () {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);
        $user->delete();

        $this->postJson('/api/devices/register', registrationBody($code))->assertStatus(422);
    });
});

describe('validation', function () {
    it('requires a code, name and platform', function (string $missing) {
        $body = registrationBody('7KQ2-M9XD');
        unset($body[$missing]);

        $this->postJson('/api/devices/register', $body)->assertStatus(422)->assertJsonValidationErrors([$missing]);
    })->with(['pairing_code', 'name', 'platform']);

    it('accepts a registration without app and OS versions', function () {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);

        $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Bare', 'platform' => 'linux'])->assertCreated();
    });

    it('rejects out-of-range or malformed fields', function (array $override, string $field) {
        $this->postJson('/api/devices/register', registrationBody('7KQ2-M9XD', $override))
            ->assertStatus(422)->assertJsonValidationErrors([$field]);
    })->with([
        'unknown platform' => [['platform' => 'blackberry'], 'platform'],
        'platform case matters' => [['platform' => 'Android'], 'platform'],
        'platform number' => [['platform' => 7], 'platform'],
        'name too long' => [['name' => str_repeat('n', 121)], 'name'],
        'name array' => [['name' => ['a']], 'name'],
        'code too long' => [['pairing_code' => str_repeat('A', 17)], 'pairing_code'],
        'code number' => [['pairing_code' => 12345678], 'pairing_code'],
        'app version too long' => [['app_version' => str_repeat('1', 33)], 'app_version'],
        'os version too long' => [['os_version' => str_repeat('1', 33)], 'os_version'],
    ]);

    it('accepts boundary lengths', function () {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);

        $this->postJson('/api/devices/register', registrationBody($code, [
            'name' => str_repeat('n', 120), 'app_version' => str_repeat('1', 32), 'os_version' => str_repeat('2', 32),
        ]))->assertCreated();
    });

    it('returns JSON errors even when the client does not ask for JSON', function () {
        $this->post('/api/devices/register', [], ['Accept' => 'text/html'])
            ->assertStatus(422)->assertHeader('Content-Type', 'application/json');
    });

    it('does not accept GET', function () {
        $this->getJson('/api/devices/register')->assertStatus(405);
    });
});

describe('throttling', function () {
    it('allows five attempts a minute per address and then answers 429 with Retry-After', function () {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/devices/register', registrationBody('NOPE-NOPE'))->assertStatus(422);
        }

        $blocked = $this->postJson('/api/devices/register', registrationBody('NOPE-NOPE'));
        $blocked->assertStatus(429);
        expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    });

    it('also blocks a correct code once the budget is spent, so guessing cannot be outpaced', function () {
        $user = User::factory()->create();
        $code = app(PairingService::class)->createCode($user);
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/devices/register', registrationBody('NOPE-NOPE'));
        }

        $this->postJson('/api/devices/register', registrationBody($code))->assertStatus(429);
        expect(Device::query()->count())->toBe(0);
    });

    it('counts per address, not globally', function () {
        foreach (range(1, 5) as $_) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->postJson('/api/devices/register', registrationBody('NOPE-NOPE'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->postJson('/api/devices/register', registrationBody('NOPE-NOPE'))->assertStatus(422);
    });

    it('lets the budget refill after a minute', function () {
        $this->freezeTime();
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/devices/register', registrationBody('NOPE-NOPE'));
        }
        $this->postJson('/api/devices/register', registrationBody('NOPE-NOPE'))->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson('/api/devices/register', registrationBody('NOPE-NOPE'))->assertStatus(422);
    });
});

describe('housekeeping', function () {
    it('prunes pairing codes a day after they expired and keeps newer ones', function () {
        $user = User::factory()->create();
        plantCode($user, 'OLDCODE1', minutes: -(60 * 25));
        plantCode($user, 'RECENT01', minutes: -60);
        plantCode($user, 'LIVECODE', minutes: 10);

        Artisan::call('model:prune', ['--model' => [DevicePairingCode::class]]);

        $remaining = DevicePairingCode::query()->pluck('code_hash')->all();
        expect($remaining)->toContain(hash('sha256', 'RECENT01'), hash('sha256', 'LIVECODE'))
            ->and($remaining)->not->toContain(hash('sha256', 'OLDCODE1'));
    });
});

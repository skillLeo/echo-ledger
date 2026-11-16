<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ShareBarsController;
use App\Models\ShareCheckResult;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| Screen-share privacy (since v1.8.1, docs/API.md "Screen-share privacy"): Share Check results the desktop app sends
| with diagnostics allowed, and the sharing-bar patterns it reads. The admin matrix: Admin/ShareChecksAdminTest.
*/

function shareCheckBody(array $overrides = []): array
{
    return ['platform' => 'windows', 'os_build' => '10.0.26100', 'app_version' => '1.8.1', 'method' => 'wgc', 'result' => 'pass',
        'reason' => null, 'meeting_app' => null, 'meeting_app_version' => null, 'share_mode' => null, ...$overrides];
}

/** @return array<string, mixed> */
function shareBarsFile(): array
{
    return json_decode(ShareBarsController::raw(), true, 32, JSON_THROW_ON_ERROR);
}

describe('POST /api/diagnostics/share-checks', function () {
    it('keeps one result with the token\'s device and its person', function () {
        $user = Fx::user();
        $device = Fx::device($user, ['platform' => 'windows']);

        $id = $this->api(Fx::token($device))->postJson('/api/diagnostics/share-checks', shareCheckBody([
            'method' => 'guided', 'result' => 'fail', 'reason' => 'flag_mismatch', 'meeting_app' => 'zoom', 'meeting_app_version' => '6.4.1', 'share_mode' => 'screen',
        ]))->assertCreated()->assertJsonStructure(['data' => ['id']])->json('data.id');

        $row = ShareCheckResult::query()->findOrFail($id);
        expect($row->user_id)->toBe($user->id)->and($row->device_id)->toBe($device->id)
            ->and($row->only(['platform', 'os_build', 'app_version', 'method', 'result', 'reason', 'meeting_app', 'meeting_app_version', 'share_mode']))
            ->toBe(['platform' => 'windows', 'os_build' => '10.0.26100', 'app_version' => '1.8.1', 'method' => 'guided', 'result' => 'fail',
                'reason' => 'flag_mismatch', 'meeting_app' => 'zoom', 'meeting_app_version' => '6.4.1', 'share_mode' => 'screen'])
            ->and($row->created_at)->not->toBeNull();
    });

    it('takes the minimal body: everything optional may be left out', function () {
        $body = shareCheckBody();
        unset($body['reason'], $body['meeting_app'], $body['meeting_app_version'], $body['share_mode']);

        $this->api(Fx::token(Fx::device()))->postJson('/api/diagnostics/share-checks', $body)->assertCreated();
        expect(ShareCheckResult::query()->sole()->reason)->toBeNull();
    });

    it('stores a result only under the caller: ids in the body are never read', function () {
        $alice = Fx::user();
        $aliceDevice = Fx::device($alice);
        $bob = Fx::user();
        $bobDevice = Fx::device($bob);

        $this->api(Fx::token($bobDevice))->postJson('/api/diagnostics/share-checks', shareCheckBody([
            'user_id' => $alice->id, 'device_id' => $aliceDevice->id, 'id' => 1,
        ]))->assertCreated();

        expect(ShareCheckResult::query()->where('user_id', $alice->id)->orWhere('device_id', $aliceDevice->id)->count())->toBe(0)
            ->and(ShareCheckResult::query()->sole()->only(['user_id', 'device_id']))->toBe(['user_id' => $bob->id, 'device_id' => $bobDevice->id]);
    });

    it('never stores a picture, a window title or any other field', function () {
        $this->api(Fx::token(Fx::device()))->postJson('/api/diagnostics/share-checks', shareCheckBody([
            'image' => base64_encode('PICTURE-BYTES'), 'window_title' => 'SECRET-WINDOW-TITLE', 'contents' => 'SECRET-CONTENTS',
        ]))->assertCreated();

        expect(json_encode(ShareCheckResult::query()->sole()->getAttributes()))->not->toContain('SECRET')->not->toContain(base64_encode('PICTURE-BYTES'));
    });

    it('refuses what is not a result', function (array $change) {
        $this->api(Fx::token(Fx::device()))->postJson('/api/diagnostics/share-checks', shareCheckBody($change))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect(ShareCheckResult::query()->count())->toBe(0);
    })->with([
        'no platform' => [['platform' => null]],
        'an unknown platform' => [['platform' => 'linux']],
        'no OS build' => [['os_build' => null]],
        'too long an OS build' => [['os_build' => str_repeat('1', 65)]],
        'no app version' => [['app_version' => null]],
        'too long an app version' => [['app_version' => str_repeat('1', 33)]],
        'an unknown method' => [['method' => 'screenshot']],
        'an unknown result' => [['result' => 'maybe']],
        'an unknown reason' => [['reason' => 'tired']],
        'an unknown meeting app' => [['meeting_app' => 'skype']],
        'too long a meeting app version' => [['meeting_app_version' => str_repeat('6', 33)]],
        'an unknown share mode' => [['share_mode' => 'region']],
        'an array for a string' => [['os_build' => ['10']]],
    ]);

    it('accepts the longest values the contract allows', function () {
        $this->api(Fx::token(Fx::device()))->postJson('/api/diagnostics/share-checks', shareCheckBody([
            'os_build' => str_repeat('1', 64), 'app_version' => str_repeat('1', 32), 'meeting_app_version' => str_repeat('6', 32), 'meeting_app' => 'other',
        ]))->assertCreated();
    });

    it('needs a device with config:read', function () {
        $this->postJson('/api/diagnostics/share-checks', shareCheckBody())->assertUnauthorized();
        $this->api(Fx::token(Fx::device(), ['library:read']))->postJson('/api/diagnostics/share-checks', shareCheckBody())->assertForbidden();
        expect(ShareCheckResult::query()->count())->toBe(0);
    });

    it('slows a device down after thirty an hour, and only that device', function () {
        $device = Fx::device();
        $token = Fx::token($device);
        RateLimiter::clear('share-check:'.$device->getAuthIdentifier());
        foreach (range(1, 30) as $_) {
            $this->api($token)->postJson('/api/diagnostics/share-checks', shareCheckBody())->assertCreated();
        }
        $this->api($token)->postJson('/api/diagnostics/share-checks', shareCheckBody())->assertStatus(429)->assertHeader('Retry-After');

        $this->api(Fx::token(Fx::device()))->postJson('/api/diagnostics/share-checks', shareCheckBody())->assertCreated();
        expect(ShareCheckResult::query()->count())->toBe(31);
    });

    it('has no route that reads results back to an app', function () {
        $readers = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && str_contains($route->uri(), 'share-check'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->values()->all();

        expect($readers)->toBe(['POST api/diagnostics/share-checks']);
    });

    it('keeps the results when the device is removed, and erases them with the person', function () {
        $user = Fx::user();
        $device = Fx::device($user);
        $this->api(Fx::token($device))->postJson('/api/diagnostics/share-checks', shareCheckBody())->assertCreated();

        $device->delete();
        expect(ShareCheckResult::query()->sole()->device_id)->toBeNull();

        $user->delete();
        expect(ShareCheckResult::query()->count())->toBe(0);
    });
});

describe('GET /api/config/share-bars', function () {
    it('serves the one file, without its note, with a strong ETag and a private cache', function () {
        $file = shareBarsFile();
        $response = $this->api(Fx::token(Fx::device()))->getJson('/api/config/share-bars')->assertOk();

        expect($response->json())->toBe(['version' => $file['version'], 'updated_at' => $file['updated_at'], 'patterns' => $file['patterns']])
            ->and($response->headers->get('ETag'))->toBe(ShareBarsController::etag(ShareBarsController::raw()))->toMatch('/^"[0-9a-f]{32}"$/')
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=300')->not->toContain('public');
    });

    it('answers 304 to the same tag, and the whole file to another', function () {
        $token = Fx::token(Fx::device());
        $etag = $this->api($token)->getJson('/api/config/share-bars')->assertOk()->headers->get('ETag');

        foreach ([$etag, 'W/'.$etag, '"other", '.$etag, '*'] as $header) {
            $notModified = $this->api($token)->withHeader('If-None-Match', $header)->getJson('/api/config/share-bars');
            expect($notModified->status())->toBe(304, $header)->and($notModified->getContent())->toBe('')
                ->and($notModified->headers->get('ETag'))->toBe($etag);
        }
        $this->api($token)->withHeader('If-None-Match', '"stale"')->getJson('/api/config/share-bars')->assertOk()->assertJsonPath('version', shareBarsFile()['version']);
    });

    it('needs a device with config:read', function () {
        $this->getJson('/api/config/share-bars')->assertUnauthorized();
        $this->api(Fx::token(Fx::device(), ['stt:token']))->getJson('/api/config/share-bars')->assertForbidden();
    });

    it('keeps the file in the shape both apps read', function () {
        $file = shareBarsFile();
        expect($file['version'])->toBeInt()->toBeGreaterThan(0)
            ->and($file['updated_at'])->toBeString()->and(strtotime($file['updated_at']))->not->toBeFalse()
            ->and($file['patterns'])->toBeArray()->not->toBeEmpty();

        $ids = [];
        foreach ($file['patterns'] as $i => $pattern) {
            $where = 'pattern '.($pattern['id'] ?? $i);
            expect(array_diff(array_keys($pattern), ['id', 'app', 'platform', 'owner', 'title', 'flags']))->toBe([], $where.': unknown keys');
            foreach (['id', 'app', 'platform', 'owner', 'title'] as $key) {
                expect($pattern[$key] ?? null)->toBeString($where.': '.$key)->not->toBe('', $where.': '.$key);
            }
            expect($pattern['id'])->toMatch('/^[a-z0-9-]+$/', $where)
                ->and($pattern['platform'])->toBeIn(['macos', 'windows'], $where)
                ->and($pattern['app'])->toBeIn(ShareCheckResult::MEETING_APPS, $where);
            if (array_key_exists('flags', $pattern)) {
                expect($pattern['flags'])->toBe('i', $where.': flags');
            }
            foreach (['owner', 'title'] as $key) {
                $regex = $pattern[$key];
                // Rust's regex crate and JavaScript both read these: no look-behind (nor look-ahead, which Rust lacks),
                // no inline flags, no backreferences.
                expect($regex)->not->toContain('(?<=', $where)->not->toContain('(?<!', $where)
                    ->not->toContain('(?=', $where)->not->toContain('(?!', $where)
                    ->not->toMatch('/\(\?[a-zA-Z]/', $where.': inline flags')
                    ->not->toMatch('/\\\\[1-9]/', $where.': backreference');
                $delimited = '~'.str_replace('~', '\~', $regex).'~u'.($pattern['flags'] ?? '');
                expect(@preg_match($delimited, ''))->not->toBeFalse($where.': '.$key.' does not compile');
            }
            $ids[] = $pattern['id'];
        }
        expect(array_unique($ids))->toHaveCount(count($ids));
    });

    it('matches the sharing bars it is meant for, and not an ordinary window', function () {
        $matches = function (string $platform, string $owner, string $title): ?string {
            foreach (shareBarsFile()['patterns'] as $p) {
                $f = 'u'.($p['flags'] ?? '');
                if ($p['platform'] === $platform && preg_match('~'.$p['owner'].'~'.$f, $owner) === 1 && preg_match('~'.$p['title'].'~'.$f, $title) === 1) {
                    return $p['id'];
                }
            }

            return null;
        };

        expect($matches('macos', 'zoom.us', 'Share Toolbar'))->toBe('zoom-share-toolbar-mac')
            ->and($matches('windows', 'Zoom.exe', 'You are screen sharing'))->toBe('zoom-share-toolbar-win')
            ->and($matches('macos', 'Google Chrome', 'meet.google.com is sharing your screen.'))->toBe('chromium-sharing-bar-mac')
            ->and($matches('windows', 'ms-teams.exe', 'Sharing control bar'))->toBe('teams-sharing-bar-win')
            ->and($matches('macos', 'zoom.us', 'Zoom Meeting'))->toBeNull()
            ->and($matches('macos', 'Google Chrome', 'Inbox - Gmail'))->toBeNull();
    });
});

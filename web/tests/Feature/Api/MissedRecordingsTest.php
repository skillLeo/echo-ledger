<?php

declare(strict_types=1);

use App\Enums\DevicePlatform;
use App\Enums\SourceApp;
use App\Models\MissedRecording;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountEraser;
use App\Services\ActionBadge;
use App\Services\ActivityFeed;
use App\Services\Workspaces\WorkspaceEraser;
use App\Services\Workspaces\Workspaces;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| Missed recordings (since v1.8, P0 0.2, docs/API.md `POST /api/devices/missed-recordings`): a call an app saw but did
| not record, sent by the app's missed-recording check. Its person reads it in Activity; staff only count it
| (Admin/MissedRecordingsAdminTest).
*/

function missedBody(array $overrides = []): array
{
    return ['client_ref' => (string) Str::uuid(), 'platform' => 'android', 'app_version' => '1.8.0', 'source_app' => 'whatsapp',
        'call_started_at' => now()->subMinutes(10)->toIso8601String(), 'call_duration_s' => 312,
        'reason' => 'helper_not_armed', ...$overrides];
}

/** @return list<MissedRecording> every row, whatever workspace the last request was in */
function missedRows(): array
{
    return unscoped(fn (): array => MissedRecording::query()->orderBy('created_at')->orderBy('id')->get()->all());
}

/**
 * The person's "Missed recording" items as GET /api/activity gives them, newest first.
 *
 * @return list<array<string, mixed>>
 */
function missedItems(User $user): array
{
    return $user->notifications()->latest()->get()->map(fn (DatabaseNotification $n): array => ActivityFeed::payload($n))
        ->filter(fn (array $item): bool => $item['kind'] === 'missed_recording')->values()->all();
}

describe('POST /api/devices/missed-recordings', function () {
    it('keeps one missed call with the token\'s device, its person and its workspace', function () {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $user = Fx::user();
        $device = Fx::device($user, ['platform' => 'android']);

        $id = $this->api(Fx::token($device))->postJson('/api/devices/missed-recordings', missedBody([
            'client_ref' => 'c1f0e2d3-1111-4a5b-9c8d-0123456789ab', 'call_started_at' => '2026-11-16T09:12:00Z',
        ]))->assertCreated()->assertJsonStructure(['data' => ['id']])->json('data.id');

        $row = unscoped(fn () => MissedRecording::query()->findOrFail($id));
        expect($id)->toBeString()->toHaveLength(26)
            ->and($row->only(['user_id', 'device_id', 'workspace_id', 'client_ref', 'platform', 'app_version', 'source_app', 'call_duration_s', 'reason']))
            ->toBe(['user_id' => $user->id, 'device_id' => $device->id, 'workspace_id' => $device->workspace_id, 'client_ref' => 'c1f0e2d3-1111-4a5b-9c8d-0123456789ab',
                'platform' => 'android', 'app_version' => '1.8.0', 'source_app' => 'whatsapp', 'call_duration_s' => 312, 'reason' => 'helper_not_armed'])
            ->and($row->call_started_at->toIso8601String())->toBe('2026-11-16T09:12:00+00:00')
            ->and($row->created_at->toIso8601String())->toBe('2026-11-16T12:00:00+00:00');
    });

    it('takes the minimal body: the app version and the length may be left out', function () {
        $body = missedBody();
        unset($body['app_version'], $body['call_duration_s']);

        $this->api(Fx::token(Fx::device()))->postJson('/api/devices/missed-recordings', $body)->assertCreated();
        expect(missedRows()[0]->only(['app_version', 'call_duration_s']))->toBe(['app_version' => null, 'call_duration_s' => null]);

        $this->api(Fx::token(Fx::device()))->postJson('/api/devices/missed-recordings', missedBody(['app_version' => null, 'call_duration_s' => null]))->assertCreated();
        expect(missedRows())->toHaveCount(2);
    });

    it('takes an older app\'s detail and keeps none of it (contract 1.8.0-c: no free text)', function () {
        $user = Fx::user();
        $this->api(Fx::token(Fx::device($user)))->postJson('/api/devices/missed-recordings', missedBody([
            'detail' => 'SECRET-DETAIL: the helper was not connected when the call with Omar started. '.str_repeat('x', 500),
        ]))->assertCreated();

        $stored = json_encode(missedRows()[0]->getAttributes()).json_encode(DB::table('notifications')->pluck('data')->all());
        expect($stored)->not->toContain('SECRET-DETAIL')->not->toContain('Omar')
            ->and(DB::getSchemaBuilder()->hasColumn('missed_recordings', 'detail'))->toBeFalse();
    });

    it('keeps the instant a start with an offset names, in UTC', function () {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $this->api(Fx::token(Fx::device()))->postJson('/api/devices/missed-recordings', missedBody(['call_started_at' => '2026-11-16T09:12:00+05:00']))->assertCreated();

        expect(missedRows()[0]->call_started_at->toIso8601String())->toBe('2026-11-16T04:12:00+00:00');
    });

    it('lets a device clock a few minutes ahead through, and keeps the start as the server\'s now', function () {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $this->api(Fx::token(Fx::device()))->postJson('/api/devices/missed-recordings', missedBody(['call_started_at' => '2026-11-16T12:04:00Z']))->assertCreated();

        expect(missedRows()[0]->call_started_at->toIso8601String())->toBe('2026-11-16T12:00:00+00:00');
    });

    it('answers the same event again with the same id, keeps the first and makes no second item', function () {
        $user = Fx::user();
        $token = Fx::token(Fx::device($user));
        $body = missedBody(['client_ref' => (string) Str::uuid()]);

        $first = $this->api($token)->postJson('/api/devices/missed-recordings', $body)->assertCreated()->json('data.id');
        $again = $this->api($token)->postJson('/api/devices/missed-recordings', [...$body, 'reason' => 'crash', 'call_duration_s' => 5])->assertOk()->json('data.id');

        expect($again)->toBe($first)
            ->and(missedRows())->toHaveCount(1)
            ->and(missedRows()[0]->only(['reason', 'call_duration_s']))->toBe(['reason' => 'helper_not_armed', 'call_duration_s' => 312])
            ->and(missedItems($user))->toHaveCount(1);
    });

    it('keeps each device\'s events apart: another device\'s same client_ref is an event of its own', function () {
        $user = Fx::user();
        $phone = Fx::device($user, ['platform' => 'android']);
        $laptop = Fx::device($user, ['platform' => 'windows']);

        $ref = (string) Str::uuid();
        $a = $this->api(Fx::token($phone))->postJson('/api/devices/missed-recordings', missedBody(['client_ref' => $ref]))->assertCreated()->json('data.id');
        $b = $this->api(Fx::token($laptop))->postJson('/api/devices/missed-recordings', missedBody(['client_ref' => $ref, 'platform' => 'windows', 'source_app' => 'zoom']))
            ->assertCreated()->json('data.id');

        expect($b)->not->toBe($a)
            ->and(collect(missedRows())->pluck('device_id')->all())->toEqualCanonicalizing([$phone->id, $laptop->id])
            ->and(missedItems($user))->toHaveCount(2);
    });

    it('answers two copies sent at once with the one row, and only the first makes an item', function () {
        $user = Fx::user();
        $device = Fx::device($user);
        $ref = (string) Str::uuid();
        $body = missedBody(['client_ref' => $ref]);
        $other = strtolower((string) Str::ulid());
        // The other copy lands between this request's look-up and its own insert.
        MissedRecording::creating(function (MissedRecording $missed) use ($device, $other, $ref): void {
            if ($missed->client_ref === $ref && DB::table('missed_recordings')->where('id', $other)->doesntExist()) {
                DB::table('missed_recordings')->insert(['id' => $other, 'user_id' => $device->user_id, 'device_id' => $device->id, 'workspace_id' => $device->workspace_id,
                    'client_ref' => $ref, 'platform' => 'android', 'source_app' => 'whatsapp', 'call_started_at' => now()->subMinutes(10),
                    'reason' => 'helper_not_armed', 'created_at' => now()]);
            }
        });

        $this->api(Fx::token($device))->postJson('/api/devices/missed-recordings', $body)->assertOk()->assertExactJson(['data' => ['id' => $other]]);
        expect(missedRows())->toHaveCount(1)->and(missedItems($user))->toBe([]);
    });

    it('stores a call only under the caller: ids in the body are never read', function () {
        $alice = Fx::user();
        $aliceDevice = Fx::device($alice);
        $bob = Fx::user();
        $bobDevice = Fx::device($bob);

        $this->api(Fx::token($bobDevice))->postJson('/api/devices/missed-recordings', missedBody([
            'user_id' => $alice->id, 'device_id' => $aliceDevice->id, 'workspace_id' => $aliceDevice->workspace_id, 'id' => strtolower((string) Str::ulid()),
        ]))->assertCreated();

        expect(missedRows())->toHaveCount(1)
            ->and(missedRows()[0]->only(['user_id', 'device_id', 'workspace_id']))->toBe(['user_id' => $bob->id, 'device_id' => $bobDevice->id, 'workspace_id' => $bobDevice->workspace_id])
            ->and(missedItems($alice))->toBe([])->and(missedItems($bob))->toHaveCount(1);
    });

    it('never stores a name, a number, audio or any other field the body carries', function () {
        $user = Fx::user();
        $this->api(Fx::token(Fx::device($user)))->postJson('/api/devices/missed-recordings', missedBody([
            'contact_name' => 'SECRET-NAME', 'phone_number' => '+923001234567', 'audio' => base64_encode('SECRET-AUDIO'), 'title' => 'SECRET-TITLE',
            'detail' => 'SECRET-DETAIL',
        ]))->assertCreated();

        $stored = json_encode(missedRows()[0]->getAttributes()).json_encode(DB::table('notifications')->pluck('data')->all());
        expect($stored)->not->toContain('SECRET')->not->toContain('923001234567')->not->toContain(base64_encode('SECRET-AUDIO'));
    });

    it('refuses what is not a missed call, and keeps nothing of it', function (array $change) {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $user = Fx::user();
        $this->api(Fx::token(Fx::device($user)))->postJson('/api/devices/missed-recordings', missedBody($change))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        expect(missedRows())->toBe([])->and(missedItems($user))->toBe([]);
    })->with([
        'no client_ref' => [['client_ref' => null]],
        'too long a client_ref' => [['client_ref' => str_repeat('a', 65)]],
        'a number for the client_ref' => [['client_ref' => 12345]],
        // Contract 1.8.0-c: an opaque id, ^[A-Za-z0-9._:-]{1,64}$ (a UUID fits).
        'a space in the client_ref' => [['client_ref' => 'c1f0 e2d3']],
        'a slash in the client_ref' => [['client_ref' => 'calls/2026/11']],
        'a UUID in braces' => [['client_ref' => '{c1f0e2d3-1111-4a5b-9c8d-0123456789ab}']],
        'a letter with an accent' => [['client_ref' => 'réf-1']],
        'a phone number as the client_ref' => [['client_ref' => '+923001234567']],
        'a line break after the client_ref' => [['client_ref' => "c1f0e2d3\nx"]],
        'no platform' => [['platform' => null]],
        'an unknown platform' => [['platform' => 'symbian']],
        'too long an app version' => [['app_version' => str_repeat('1', 33)]],
        'no source app' => [['source_app' => null]],
        'an unknown source app' => [['source_app' => 'myspace']],
        // Contract 1.8.0-b: the sessions' own values only (plus browser), never the short names 1.8.0-a listed.
        'phone for phone_call' => [['source_app' => 'phone']],
        'meet for google_meet' => [['source_app' => 'meet']],
        'teams for microsoft_teams' => [['source_app' => 'teams']],
        'no start' => [['call_started_at' => null]],
        'a start that is no date' => [['call_started_at' => 'yesterday-ish']],
        'a start in the future' => [['call_started_at' => '2026-11-16T12:06:00Z']],
        'a start more than 30 days ago' => [['call_started_at' => '2026-10-17T11:59:00Z']],
        'a negative length' => [['call_duration_s' => -1]],
        'a length over a day' => [['call_duration_s' => 86401]],
        'a length with a fraction' => [['call_duration_s' => 1.5]],
        'no reason' => [['reason' => null]],
        'an unknown reason' => [['reason' => 'tired']],
        'an array for a string' => [['app_version' => ['1.8.0']]],
    ]);

    it('accepts the edges the contract allows', function (array $change) {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $this->api(Fx::token(Fx::device()))->postJson('/api/devices/missed-recordings', missedBody($change))->assertCreated();
    })->with([
        'a 64-character client_ref' => [['client_ref' => str_repeat('a', 64)]],
        'a UUID' => [['client_ref' => 'C1F0E2D3-1111-4A5B-9C8D-0123456789AB']],
        'every sign an opaque id may have' => [['client_ref' => 'phone:call.2026-11-16_09:12']],
        'a 32-character app version' => [['app_version' => str_repeat('1', 32)]],
        'a call of no length' => [['call_duration_s' => 0]],
        'a call of a whole day' => [['call_duration_s' => 86400]],
        'a start 30 days ago' => [['call_started_at' => '2026-10-17T12:00:00Z']],
        'a start just now' => [['call_started_at' => '2026-11-16T12:00:00Z']],
    ]);

    it('takes every reason and every platform', function () {
        $device = Fx::device();
        foreach (array_keys(MissedRecording::REASONS) as $reason) {
            $this->api(Fx::token($device))->postJson('/api/devices/missed-recordings', missedBody(['reason' => $reason]))->assertCreated();
        }
        foreach (DevicePlatform::cases() as $platform) {
            $this->api(Fx::token($device))->postJson('/api/devices/missed-recordings', missedBody(['platform' => $platform->value]))->assertCreated();
        }

        expect(missedRows())->toHaveCount(count(MissedRecording::REASONS) + count(DevicePlatform::cases()));
    });

    it('takes every source app of the sessions and a browser, kept as sent', function () {
        $device = Fx::device();
        $sent = [...array_map(fn (SourceApp $app): string => $app->value, SourceApp::cases()), 'browser'];
        $refs = [];
        foreach ($sent as $app) {
            $refs[$app] = (string) Str::uuid();
            $this->api(Fx::token($device))->postJson('/api/devices/missed-recordings', missedBody(['source_app' => $app, 'client_ref' => $refs[$app]]))->assertCreated();
        }

        $stored = collect(missedRows())->mapWithKeys(fn (MissedRecording $m): array => [array_search($m->client_ref, $refs, true) => $m->source_app])->all();
        expect($stored)->toEqualCanonicalizing(array_combine($sent, $sent))->and(MissedRecording::sourceApps())->toEqualCanonicalizing($sent);
    });

    it('needs a device with config:read', function () {
        $this->postJson('/api/devices/missed-recordings', missedBody())->assertUnauthorized();
        $this->api(Fx::token(Fx::device(), ['library:read']))->postJson('/api/devices/missed-recordings', missedBody())
            ->assertForbidden()->assertJsonPath('code', 'missing_ability');

        expect(missedRows())->toBe([]);
    });

    it('slows a device down after sixty an hour, and only that device', function () {
        $device = Fx::device();
        $token = Fx::token($device);
        RateLimiter::clear('missed-recording:'.$device->getAuthIdentifier());
        foreach (range(1, 60) as $_) {
            $this->api($token)->postJson('/api/devices/missed-recordings', missedBody())->assertCreated();
        }
        $this->api($token)->postJson('/api/devices/missed-recordings', missedBody())
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'rate_limited');

        $this->api(Fx::token(Fx::device()))->postJson('/api/devices/missed-recordings', missedBody())->assertCreated();
        expect(missedRows())->toHaveCount(61);
    });

    it('has no route that reads missed recordings back to an app', function () {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && str_contains($route->uri(), 'missed'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->values()->all();

        expect($routes)->toBe(['POST api/devices/missed-recordings']);
    });

    it('keeps the rows when the device is removed, and erases them with the person', function () {
        $user = Fx::user();
        $device = Fx::device($user);
        $this->api(Fx::token($device))->postJson('/api/devices/missed-recordings', missedBody())->assertCreated();

        $device->delete();
        expect(missedRows()[0]->device_id)->toBeNull();

        $user->delete();
        expect(missedRows())->toBe([]);
    });
});

describe('the Activity item', function () {
    it('tells the device\'s person: the call, its length and the reason, with the reason and the id for the app\'s fix', function () {
        $user = Fx::user();
        $token = Fx::token(Fx::device($user));
        $id = $this->api($token)->postJson('/api/devices/missed-recordings', missedBody())->assertCreated()->json('data.id');

        $items = collect($this->api($token)->getJson('/api/activity')->assertOk()->json('data'))->where('kind', 'missed_recording')->values()->all();
        expect($items)->toHaveCount(1)
            ->and($items[0])->toMatchArray(['kind' => 'missed_recording', 'title' => 'Missed recording', 'body' => 'WhatsApp call · 5:12 · The helper was not connected',
                'session_id' => null, 'read' => false, 'reason' => 'helper_not_armed', 'missed_recording_id' => $id])
            // Since 2.0.0-r (BE-02) every item also has repeat_count, group and resolved.
            ->and(array_keys($items[0]))->toBe(['id', 'kind', 'title', 'body', 'session_id', 'read', 'at', 'repeat_count', 'group', 'resolved', 'reason', 'missed_recording_id']);
    });

    it('says the call and the reason in plain words', function (array $change, string $body) {
        $user = Fx::user();
        $this->api(Fx::token(Fx::device($user)))->postJson('/api/devices/missed-recordings', missedBody($change))->assertCreated();

        expect(missedItems($user)[0]['body'])->toBe($body);
    })->with([
        'a permission' => [['reason' => 'permission', 'call_duration_s' => 45], 'WhatsApp call · 0:45 · A permission was off'],
        'detection' => [['reason' => 'detection', 'source_app' => 'whatsapp_business'], 'WhatsApp Business call · 5:12 · The call was not detected'],
        'the microphone' => [['reason' => 'mic_busy', 'source_app' => 'zoom', 'call_duration_s' => 3725], 'Zoom call · 1:02:05 · Another app was using the microphone'],
        'a crash' => [['reason' => 'crash', 'source_app' => 'phone_call'], 'Phone call · 5:12 · The app stopped during the call'],
        'the plan' => [['reason' => 'plan', 'source_app' => 'google_meet'], 'Google Meet call · 5:12 · Your plan did not allow the recording'],
        'not known' => [['reason' => 'unknown', 'source_app' => 'other'], 'Call · 5:12 · The reason is not known'],
        'a browser' => [['source_app' => 'browser', 'call_duration_s' => 600], 'Call in a browser · 10:00 · The helper was not connected'],
        'Teams' => [['source_app' => 'microsoft_teams'], 'Microsoft Teams call · 5:12 · The helper was not connected'],
        'no length' => [['call_duration_s' => null], 'WhatsApp call · The helper was not connected'],
    ]);

    it('gives only a missed recording the reason and the id: the other kinds keep their shape', function () {
        $user = Fx::user();
        $token = Fx::token(Fx::device($user));
        $this->api($token)->postJson('/api/devices/missed-recordings', missedBody())->assertCreated();

        $paired = collect($this->api($token)->getJson('/api/activity')->assertOk()->json('data'))->firstWhere('kind', 'device_paired');
        expect(array_keys($paired))->toBe(['id', 'kind', 'title', 'body', 'session_id', 'read', 'at', 'repeat_count', 'group', 'resolved']);
    });

    it('goes to the device\'s person only', function () {
        $alice = Fx::user();
        $bob = Fx::user();
        $this->api(Fx::token(Fx::device($bob)))->postJson('/api/devices/missed-recordings', missedBody())->assertCreated();

        $aliceItems = $this->api(Fx::token(Fx::device($alice)))->getJson('/api/activity')->assertOk()->json('data');
        expect(collect($aliceItems)->where('kind', 'missed_recording')->all())->toBe([])->and(missedItems($bob))->toHaveCount(1);
    });

    it('needs action: the bell counts it and Activity shows it under Needs action until it is read', function () {
        $user = Fx::user();
        $this->api(Fx::token(Fx::device($user)))->postJson('/api/devices/missed-recordings', missedBody(['source_app' => 'zoom']))->assertCreated();
        $user->notifications()->where('data', 'not like', '%missed_recording%')->update(['read_at' => now()]);
        app('auth')->forgetGuards();
        $badge = fn (): int => app(ActionBadge::class)->count($user, (int) $user->current_workspace_id);
        // What the page lists between "Needs action" and the next heading.
        $needs = fn (): string => (string) preg_replace('/^.*?>Needs action<\/h2>|<h2 class="el-section-header".*$/s', '', (string) $this->get('/app/activity')->assertOk()->getContent());

        $this->actingAs($user, 'web');
        expect($badge())->toBe(1)->and(ActionBadge::needsAction('missed_recording'))->toBeTrue()
            ->and($needs())->toContain('Missed recording')->toContain('Zoom call · 5:12 · The helper was not connected')
            // On a phone its line may take two lines, so the reason at its end is never cut off.
            ->toContain('el-row-meta el-row-meta-wraps');

        $user->unreadNotifications()->update(['read_at' => now()]);
        $page = (string) $this->get('/app/activity')->assertOk()->getContent();
        expect($badge())->toBe(0)->and($page)->not->toContain('>Needs action</h2>')->toContain('Zoom call · 5:12 · The helper was not connected');
    });
});

describe('retention', function () {
    /** The ids of the missed recordings the person's Activity items are about. */
    function missedItemIds(User $user): array
    {
        return array_column(missedItems($user->fresh()), 'missed_recording_id');
    }

    it('deletes a missed call and its Activity item 90 days after the call, with the daily model:prune, and keeps newer ones', function () {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $device = Fx::device();
        $row = fn (string $at): MissedRecording => unscoped(fn (): MissedRecording => MissedRecording::query()->create([
            'user_id' => $device->user_id, 'device_id' => $device->id, 'workspace_id' => $device->workspace_id, 'client_ref' => (string) Str::uuid(), 'platform' => 'android',
            'source_app' => 'whatsapp', 'call_started_at' => Carbon::parse($at), 'reason' => 'crash']));
        $old = $row('2026-08-18 11:59:59');
        $dayNinety = $row('2026-08-18 12:00:01');
        $recent = $row('2026-11-15 09:00:00');
        expect(missedItemIds($device->user))->toEqualCanonicalizing([$old->id, $dayNinety->id, $recent->id]);

        // The scheduled command names no model: it finds this one by itself.
        Artisan::call('model:prune', ['--pretend' => true]);
        expect(Artisan::output())->toContain(MissedRecording::class)->and(missedRows())->toHaveCount(3);

        Artisan::call('model:prune');
        expect(collect(missedRows())->pluck('id')->all())->toEqualCanonicalizing([$dayNinety->id, $recent->id])
            ->and(missedItemIds($device->user))->toEqualCanonicalizing([$dayNinety->id, $recent->id]);
    });

    it('takes the Activity item with a deleted row, and only that one', function () {
        $user = Fx::user();
        $token = Fx::token(Fx::device($user));
        $gone = $this->api($token)->postJson('/api/devices/missed-recordings', missedBody())->assertCreated()->json('data.id');
        $kept = $this->api($token)->postJson('/api/devices/missed-recordings', missedBody())->assertCreated()->json('data.id');

        unscoped(fn () => MissedRecording::query()->findOrFail($gone)->delete());

        expect(missedItemIds($user))->toBe([$kept])->and($user->fresh()->notifications()->where('data', 'like', '%device_paired%')->count())->toBe(1);
    });

    it('takes the Activity items with a deleted workspace\'s rows, and keeps the person\'s others', function () {
        $owner = Fx::user();
        $team = app(Workspaces::class)->createFor($owner, 'Short-lived team');
        $inTeam = $this->api(Fx::token(Fx::device($owner, ['workspace_id' => $team->id])))->postJson('/api/devices/missed-recordings', missedBody())->assertCreated()->json('data.id');
        $personal = $this->api(Fx::token(Fx::device($owner)))->postJson('/api/devices/missed-recordings', missedBody())->assertCreated()->json('data.id');
        app('auth')->forgetGuards();
        expect(missedItemIds($owner))->toEqualCanonicalizing([$inTeam, $personal]);

        app(WorkspaceEraser::class)->erase($team->fresh(), $owner->fresh());

        expect(Workspace::query()->find($team->id))->toBeNull()
            ->and(collect(missedRows())->pluck('id')->all())->toBe([$personal])
            ->and(missedItemIds($owner))->toBe([$personal]);
    });

    it('lets every later listener of a deleted workspace run: taking the items answers nothing, so nothing stops', function () {
        $owner = Fx::user();
        $team = app(Workspaces::class)->createFor($owner, 'Short-lived team');
        $this->api(Fx::token(Fx::device($owner, ['workspace_id' => $team->id])))->postJson('/api/devices/missed-recordings', missedBody())->assertCreated();
        app('auth')->forgetGuards();
        // `deleting` stops at the first listener that answers anything; this one comes after the Activity feed's.
        $heard = new ArrayObject;
        Workspace::deleting(function (Workspace $workspace) use ($heard): void {
            $heard[] = $workspace->id;
        });

        app(WorkspaceEraser::class)->erase($team->fresh(), $owner->fresh());

        expect($heard->getArrayCopy())->toBe([$team->id])->and(missedItemIds($owner))->toBe([]);
    });

    it('takes the Activity items with an erased account, as all its notifications', function () {
        $user = Fx::user();
        $this->api(Fx::token(Fx::device($user)))->postJson('/api/devices/missed-recordings', missedBody())->assertCreated();
        app('auth')->forgetGuards();

        app(AccountEraser::class)->erase($user->fresh());

        expect(missedRows())->toBe([])
            ->and(DB::table('notifications')->where('notifiable_id', $user->id)->where('data', 'like', '%missed_recording%')->count())->toBe(0);
    });
});

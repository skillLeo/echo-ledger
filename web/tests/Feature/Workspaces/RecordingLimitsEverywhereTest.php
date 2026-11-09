<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sharing\RequestLinks;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6: the plan's recordings a day and longest recording on every route that creates or finishes a recording (docs/API.md
| "Errors and counting rules"): the device API, the console's uploads and request links. `403 limit_reached` with
| `limit` to apps of v1.6 and later, the code they know (storage_limit_reached) to older ones.
*/

uses(ApiClient::class);

function limitPlan(User $person, array $limits): void
{
    $plan = Plan::query()->create(['key' => 'lim-'.substr(md5((string) microtime(true)), 0, 6), 'name' => 'Limited', 'price_usd' => 0, 'features' => array_fill_keys(Plan::FEATURES, true), ...$limits]);
    Workspace::query()->whereKey($person->current_workspace_id)->update(['plan_id' => $plan->id]);
}

beforeEach(function () {
    Fx::fakeRecordingsDisk();
});

afterEach(function () {
    Fx::cleanupDisks();
});

it('refuses a file longer than the plan allows on POST /api/sessions/{id}/recordings', function () {
    $person = Fx::user();
    limitPlan($person, ['max_file_minutes' => 5]);
    $token = Fx::token(Fx::device($person, ['app_version' => '1.6.0']));
    $open = $this->api($token)->postJson('/api/sessions', array_diff_key(Fx::sessionPayload(), ['artifacts' => 1, 'duration_ms' => 1, 'ended_at' => 1]))->assertCreated()->json('session.id');

    $this->api($token)->postJson("/api/sessions/{$open}/recordings", Fx::artifact(['role' => 'primary', 'duration_ms' => 6 * 60000]))
        ->assertForbidden()->assertJson(['code' => 'limit_reached', 'limit' => 'max_file_minutes']);
    $this->api($token)->postJson("/api/sessions/{$open}/recordings", Fx::artifact(['role' => 'primary', 'duration_ms' => 4 * 60000]))->assertCreated();
});

it('tells an app older than v1.6 the code it knows', function () {
    $person = Fx::user();
    limitPlan($person, ['max_file_minutes' => 5]);
    $token = Fx::token(Fx::device($person, ['app_version' => '1.5.2']));
    $open = $this->api($token)->postJson('/api/sessions', array_diff_key(Fx::sessionPayload(), ['artifacts' => 1, 'duration_ms' => 1, 'ended_at' => 1]))->assertCreated()->json('session.id');

    $this->api($token)->postJson("/api/sessions/{$open}/recordings", Fx::artifact(['role' => 'primary', 'duration_ms' => 6 * 60000]))
        ->assertForbidden()->assertJson(['code' => 'storage_limit_reached', 'limit' => 'max_file_minutes']);
});

it('checks the longest recording when a recording\'s upload is finished, and when a repeated create finishes it', function () {
    $person = Fx::user();
    $token = Fx::token($device = Fx::device($person, ['app_version' => '1.6.0']));
    $created = $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['duration_ms' => 6 * 60000, 'artifacts' => [Fx::artifact(['duration_ms' => 6 * 60000])]]))->assertCreated();
    $recording = (string) $created->json('recordings.0.id');
    // The plan changed while the file was being sent.
    limitPlan($person, ['max_file_minutes' => 5]);
    $this->api($token)->postJson("/api/recordings/{$recording}/complete")->assertForbidden()->assertJson(['code' => 'limit_reached', 'limit' => 'max_file_minutes']);
    expect(Recording::query()->find($recording)?->isReady())->toBeFalse();

    $payload = array_diff_key(Fx::sessionPayload(['client_ref' => 'again-1']), ['artifacts' => 1, 'duration_ms' => 1, 'ended_at' => 1]);
    $this->api($token)->postJson('/api/sessions', $payload)->assertCreated();
    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['client_ref' => 'again-1', 'duration_ms' => 6 * 60000, 'artifacts' => [Fx::artifact(['duration_ms' => 6 * 60000])]]))
        ->assertForbidden()->assertJson(['code' => 'limit_reached', 'limit' => 'max_file_minutes']);
    expect($device->id)->toBeString();
});

it('applies both limits to the console\'s uploads, when started and when finished', function () {
    $person = Fx::user();
    limitPlan($person, ['max_file_minutes' => 5, 'uploads_per_day' => 1]);
    $this->actingAs($person, 'web');
    $file = fn (string $name, ?int $ms) => ['name' => $name, 'size' => 4096, 'sha256' => hash('sha256', $name), 'type' => 'audio/wav', 'duration_ms' => $ms];

    $this->postJson(route('admin.uploads.store'), $file('long.wav', 6 * 60000))->assertForbidden()->assertJson(['limit' => 'max_file_minutes']);
    $started = $this->postJson(route('admin.uploads.store'), $file('ok.wav', 60000))->assertCreated();
    $this->postJson(route('admin.uploads.store'), $file('second.wav', 60000))->assertForbidden()->assertJson(['limit' => 'uploads_per_day']);

    $session = CaptureSession::query()->latest('created_at')->firstOrFail();
    $session->forceFill(['duration_ms' => 7 * 60000])->save();
    $this->postJson(route('admin.uploads.complete', (string) $started->json('recording_id')))->assertForbidden()->assertJson(['limit' => 'max_file_minutes']);
});

it('counts a recording sent to a request link as one of its owner\'s recordings a day', function () {
    $owner = Fx::user();
    limitPlan($owner, ['uploads_per_day' => 1]);
    Fx::session($owner);
    ['link' => $link, 'url' => $url] = app(RequestLinks::class)->create((int) $owner->current_workspace_id, $owner->id, null, 'Send me', null, 5, 100);

    $this->postJson((string) parse_url($url, PHP_URL_PATH).'/files', ['name' => 'voice.ogg', 'size' => 4096, 'sha256' => str_repeat('b', 64), 'type' => 'audio/ogg'])
        ->assertForbidden()->assertJson(['limit' => 'uploads_per_day']);
    expect($link->uploads()->count())->toBe(0);
});

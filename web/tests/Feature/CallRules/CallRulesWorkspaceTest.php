<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

/*
 * Security review of the call rules (2.1.0-a): "Transcribe automatically" is decided by the plan of the workspace the
 * recording or the asking device belongs to, never by the person's current workspace. One person, a paid and a Free
 * workspace: neither lends its plan to the other.
 */

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    $this->configureAssemblyAi();
    Http::fake(['*' => Http::response(['id' => 'job_1', 'status' => 'queued', 'upload_url' => 'https://cdn.example/u'], 200)]);
    $this->person = User::factory()->create();
    $paidPlan = Plan::query()->create(['key' => 'paid_ws', 'name' => 'Paid', 'features' => ['platform_transcription' => true, 'auto_transcribe' => true]]);
    $freePlan = Plan::query()->create(['key' => 'free_ws', 'name' => 'Free here', 'features' => ['platform_transcription' => false, 'auto_transcribe' => false]]);
    $this->paid = app(Workspaces::class)->createFor($this->person, 'Paid', $paidPlan);
    $this->free = app(Workspaces::class)->createFor($this->person, 'Free', $freePlan);
    app(Entitlements::class)->forget();
});

/** A device of the person in [$workspaceId], with the person's current workspace set to [$current]. */
function deviceIn(object $test, int $workspaceId, int $current): string
{
    $test->person->forceFill(['current_workspace_id' => $current])->save();
    $device = Device::factory()->create(['user_id' => $test->person->id, 'workspace_id' => $workspaceId]);
    app(Entitlements::class)->forget();

    return $device->createToken('device', Device::ABILITIES, now()->addDay())->plainTextToken;
}

it('refuses auto-transcribe for a Free workspace\'s device while the current workspace is paid', function () {
    $this->asToken(deviceIn($this, $this->free->id, $this->paid->id));

    $this->putJson('/api/call-rules/default', ['action' => 'transcribe'])->assertForbidden()->assertJsonPath('feature', 'auto_transcribe');
    $this->putJson('/api/call-rules/sources/zoom', ['action' => 'transcribe'])->assertForbidden();
    $this->putJson('/api/call-rules/contacts', ['key' => 'tel:+923001234567', 'action' => 'transcribe'])->assertForbidden();
});

it('allows it for a paid workspace\'s device while the current workspace is Free', function () {
    $this->asToken(deviceIn($this, $this->paid->id, $this->free->id));

    $this->putJson('/api/call-rules/default', ['action' => 'transcribe'])->assertOk();
    expect($this->getJson('/api/call-rules/resolve')->json())->toBe(['action' => 'transcribe', 'by' => 'default']);
});

it('reads a stored "transcribe" by the recording\'s workspace when the upload completes', function () {
    $this->person->forceFill(['after_call_default' => 'transcribe'])->save();

    // A recording in the Free workspace, the current one paid: kept as audio, nothing sent to an engine.
    $this->asToken(deviceIn($this, $this->free->id, $this->paid->id));
    $inFree = $this->uploadRecording(Fixtures::audio(3000))['session'];
    expect(DB::table('transcripts')->where('capture_session_id', $inFree)->where('kind', TranscriptKind::Final->value)->exists())->toBeFalse()
        ->and(CaptureSession::query()->find($inFree)->choice)->toBe('keep_audio');

    // A recording in the paid workspace, the current one Free: transcribed.
    $this->asToken(deviceIn($this, $this->paid->id, $this->free->id));
    $inPaid = $this->uploadRecording(Fixtures::audio(3001))['session'];
    expect(DB::table('transcripts')->where('capture_session_id', $inPaid)->where('kind', TranscriptKind::Final->value)->exists())->toBeTrue();
});

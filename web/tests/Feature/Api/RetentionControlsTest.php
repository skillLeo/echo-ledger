<?php

declare(strict_types=1);

use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Pages\Settings;
use App\Filament\Widgets\StorageOverview;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Recording;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/* Retention controls (B17): per-client retention, the Keep pin, the 48-hour undo bin and the storage meter. */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
});

/** A finished recording with its encrypted file, created $days days ago. */
function retainedSession(object $test, int $days, array $attributes = []): CaptureSession
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(3000), ['status' => 'completed', ...$attributes]);
    CaptureSession::query()->whereKey($session->id)->update(['created_at' => now()->subDays($days)]);

    return $session->refresh();
}

describe('retention', function () {
    it('bins recordings past the general retention but not kept ones or ones still recording', function () {
        app(SettingsRepository::class)->set('retention_days', 7);
        $old = retainedSession($this, 8);
        $kept = retainedSession($this, 90, ['keep_forever' => true]);
        $young = retainedSession($this, 6);
        $live = CaptureSession::factory()->open()->create(['user_id' => $this->device->user_id]);
        CaptureSession::query()->whereKey($live->id)->update(['created_at' => now()->subDays(30)]);

        Artisan::call('recordings:retention');

        expect(CaptureSession::query()->pluck('id')->sort()->values()->all())->toBe(collect([$kept->id, $young->id, $live->id])->sort()->values()->all())
            ->and(CaptureSession::onlyTrashed()->sole()->id)->toBe($old->id);
    });

    it('lets a client use its own retention', function () {
        app(SettingsRepository::class)->set('retention_days', 30);
        $strict = Client::factory()->create(['user_id' => $this->device->user_id, 'retention_days' => 7]);
        $relaxed = Client::factory()->create(['user_id' => $this->device->user_id, 'retention_days' => 90]);
        $a = retainedSession($this, 10, ['client_id' => $strict->id]);
        $b = retainedSession($this, 60, ['client_id' => $relaxed->id]);
        $c = retainedSession($this, 40);

        Artisan::call('recordings:retention');

        expect(CaptureSession::onlyTrashed()->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $c->id])->sort()->values()->all())
            ->and(CaptureSession::query()->find($b->id))->not->toBeNull();
    });

    it('sets Keep and the client retention through the API', function () {
        $session = retainedSession($this, 1);
        $this->patchJson("/api/sessions/{$session->id}", ['keep' => true])->assertOk()->assertJsonPath('data.keep', true);
        $this->postJson('/api/sessions/bulk', ['ids' => [$session->id], 'action' => 'unkeep'])->assertOk();
        expect($session->fresh()->keep_forever)->toBeFalse();

        $client = $this->postJson('/api/clients', ['name' => 'Acme', 'retention_days' => 14])->assertCreated();
        expect($client->json('data.retention_days'))->toBe(14);
        $this->patchJson('/api/clients/'.$client->json('data.id'), ['retention_days' => 400])->assertStatus(422);
        $this->patchJson('/api/clients/'.$client->json('data.id'), ['retention_days' => null])->assertOk()->assertJsonPath('data.retention_days', null);
    });
});

describe('the undo bin', function () {
    it('lists a deleted recording for 48 hours and restores it to every device', function () {
        $session = retainedSession($this, 1, ['title' => 'Acme kickoff']);
        $path = Recording::query()->where('capture_session_id', $session->id)->value('stored_path');
        $this->deleteJson("/api/sessions/{$session->id}")->assertNoContent();

        $bin = $this->getJson('/api/bin')->assertOk()->json('data');
        expect($bin)->toHaveCount(1)->and($bin[0]['title'])->toBe('Acme kickoff')->and($bin[0]['erase_at'])->not->toBeNull();
        $sync = $this->getJson('/api/library')->json('server_time');
        $this->travel(2)->seconds();

        $this->postJson("/api/sessions/{$session->id}/restore")->assertOk()->assertJsonPath('data.deleted', false)->assertJsonPath('data.title', 'Acme kickoff');

        expect(Storage::disk('recordings')->exists($path))->toBeTrue()
            ->and(collect($this->getJson('/api/library?since='.urlencode($sync))->json('data'))->pluck('id')->all())->toContain($session->id)
            ->and($this->getJson('/api/bin')->json('data'))->toBe([]);
    });

    it('cannot restore once the 48 hours are over, and empties itself', function () {
        $session = retainedSession($this, 1);
        $this->deleteJson("/api/sessions/{$session->id}")->assertNoContent();
        CaptureSession::onlyTrashed()->whereKey($session->id)->update(['deleted_at' => now()->subHours(49)]);

        $this->postJson("/api/sessions/{$session->id}/restore")->assertStatus(410)->assertJsonPath('code', 'restore_expired');
        expect($this->getJson('/api/bin')->json('data'))->toBe([]);

        Artisan::call('recordings:retention');
        expect(Recording::query()->where('capture_session_id', $session->id)->count())->toBe(0)
            ->and(CaptureSession::withTrashed()->find($session->id)->media_erased_at)->not->toBeNull();
    });

    it('erases a binned recording now on request, and only the owner\'s', function () {
        $session = retainedSession($this, 1);
        $this->deleteJson("/api/sessions/{$session->id}")->assertNoContent();
        $foreign = CaptureSession::factory()->create();
        $foreign->delete();

        $this->deleteJson("/api/bin/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/bin/{$session->id}")->assertNoContent();

        expect(Recording::query()->where('capture_session_id', $session->id)->count())->toBe(0);
        $this->postJson("/api/sessions/{$session->id}/restore")->assertStatus(410);
        $this->postJson("/api/sessions/{$foreign->id}/restore")->assertNotFound();
    });
});

describe('storage', function () {
    it('reports the recordings and the server disk', function () {
        retainedSession($this, 1);
        $answer = $this->getJson('/api/storage')->assertOk();

        expect($answer->json('recordings_count'))->toBe(1)
            ->and($answer->json('recordings_bytes'))->toBeGreaterThan(0)
            ->and($answer->json('retention_days'))->toBe(app(SettingsRepository::class)->retentionDays())
            ->and($answer->json())->toHaveKeys(['disk_free_bytes', 'disk_total_bytes']);
    });
});

describe('the console', function () {
    it('restores and erases from Recently deleted', function () {
        $admin = $this->signInAsAdmin();
        $restore = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Bring me back']);
        $erase = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Erase me']);
        $restore->delete();
        $erase->delete();

        $this->panelComponent(RecentlyDeleted::class)->assertCanSeeTableRecords([$restore, $erase])
            ->callTableAction('restore', $restore)->assertNotified('Recording restored')
            ->callTableAction('eraseNow', $erase)->assertNotified('Recording erased');

        expect(CaptureSession::query()->find($restore->id))->not->toBeNull()
            ->and(CaptureSession::withTrashed()->find($erase->id)->media_erased_at)->not->toBeNull();
    });

    it('offers 7, 14 and 30 days and a custom number', function () {
        $this->signInAsAdmin();
        $page = $this->panelComponent(Settings::class)->assertSet('data.retention_preset', '30');
        $page->set('data.retention_preset', '14')->assertSet('data.retention_days', 14);
    });

    it('shows the storage meter', function () {
        $this->signInAsAdmin();
        $this->panelComponent(StorageOverview::class)->assertOk()->assertSee('Recordings')->assertSee('deleted after');
    });
});

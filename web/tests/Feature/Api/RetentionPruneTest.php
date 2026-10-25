<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\WebhookEvent;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device] = $this->enroll();
});

/** A session with a ready encrypted recording and a transcript, created $days days ago. */
function agedSession(object $test, int $days, array $attributes = []): CaptureSession
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(3000), array_merge(['status' => 'completed'], $attributes));
    $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'kept or not']);
    $at = now()->subDays($days);
    CaptureSession::withTrashed()->whereKey($session->id)->update(['created_at' => $at, 'updated_at' => $at]);
    Recording::query()->where('capture_session_id', $session->id)->update(['created_at' => $at]);
    Transcript::query()->where('capture_session_id', $session->id)->update(['created_at' => $at]);

    return $session->refresh();
}

/** The scheduled clean-ups: retention moves old recordings to the undo bin (B17), model:prune drops old rows. */
function prune(): void
{
    Artisan::call('recordings:retention');
    Artisan::call('model:prune');
}

describe('retention window', function () {
    it('bins recordings older than the retention, erases them after 48 hours and keeps newer ones', function () {
        $old = agedSession($this, 40);
        $fresh = agedSession($this, 5);
        $oldPath = Recording::query()->where('capture_session_id', $old->id)->value('stored_path');
        $freshPath = Recording::query()->where('capture_session_id', $fresh->id)->value('stored_path');

        prune();
        expect(CaptureSession::query()->find($old->id))->toBeNull()->and(CaptureSession::withTrashed()->find($old->id)->restorable())->toBeTrue();
        emptyUndoBin();

        expect(CaptureSession::withTrashed()->find($old->id)->media_erased_at)->not->toBeNull()
            ->and(Recording::query()->where('capture_session_id', $old->id)->count())->toBe(0)
            ->and(Transcript::query()->where('capture_session_id', $old->id)->count())->toBe(0)
            ->and(Storage::disk('recordings')->exists($oldPath))->toBeFalse()
            ->and(CaptureSession::query()->find($fresh->id))->not->toBeNull()
            ->and(Recording::query()->where('capture_session_id', $fresh->id)->count())->toBe(1)
            ->and(Storage::disk('recordings')->exists($freshPath))->toBeTrue();
    });

    it('follows the configured retention', function (int $setting, int $ageDays, bool $pruned) {
        app(SettingsRepository::class)->set('retention_days', $setting);
        $session = agedSession($this, $ageDays);

        prune();

        expect(CaptureSession::query()->find($session->id) === null)->toBe($pruned);
    })->with([
        'seven days: eight old is pruned' => [7, 8, true],
        'seven days: six old is kept' => [7, 6, false],
        'a year: a month old is kept' => [365, 31, false],
        'a year: 366 days old is pruned' => [365, 366, true],
        'one day: two days old is pruned' => [1, 2, true],
    ]);

    it('clamps an out-of-range retention instead of purging everything', function (int $setting, int $ageDays, bool $pruned) {
        app(SettingsRepository::class)->set('retention_days', $setting);
        $session = agedSession($this, $ageDays);

        prune();

        expect(CaptureSession::query()->find($session->id) === null)->toBe($pruned);
    })->with([
        'zero acts as one day, so a recent session survives' => [0, 0, false],
        'zero acts as one day, so a two day old one goes' => [0, 2, true],
        'negative acts as one day' => [-30, 0, false],
        'beyond a year acts as 365 days' => [100000, 300, false],
        'beyond a year still prunes past 365' => [100000, 400, true],
    ]);

    it('judges the cutoff to the second', function () {
        $this->freezeTime();
        app(SettingsRepository::class)->set('retention_days', 10);
        $justInside = agedSession($this, 0);
        $justOutside = agedSession($this, 0);
        $cutoff = now()->subDays(10);
        CaptureSession::query()->whereKey($justInside->id)->update(['created_at' => $cutoff->copy()->addSecond()]);
        CaptureSession::query()->whereKey($justOutside->id)->update(['created_at' => $cutoff->copy()->subSecond()]);

        prune();

        expect(CaptureSession::query()->find($justInside->id))->not->toBeNull()->and(CaptureSession::query()->find($justOutside->id))->toBeNull();
    });

    it('does nothing when only a pretend run is requested', function () {
        $old = agedSession($this, 90);

        Artisan::call('model:prune', ['--pretend' => true]);

        expect(CaptureSession::query()->find($old->id))->not->toBeNull()->and(Recording::query()->where('capture_session_id', $old->id)->count())->toBe(1);
    });

    it('is safe to run twice', function () {
        agedSession($this, 90);

        prune();
        prune();
        emptyUndoBin();
        emptyUndoBin();

        expect(CaptureSession::query()->count())->toBe(0)->and(CaptureSession::onlyTrashed()->count())->toBe(1)->and(Recording::query()->count())->toBe(0);
    });
});

describe('tombstones', function () {
    it('keeps a recent tombstone so devices can still learn about the deletion', function () {
        $session = agedSession($this, 5);
        $session->delete();

        prune();

        expect(CaptureSession::onlyTrashed()->find($session->id))->not->toBeNull();
    });

    it('keeps a tombstone for the full retention period counted from the deletion, not from the session\'s creation', function () {
        $this->freezeTime();
        $session = agedSession($this, 29);
        $session->delete();
        $this->travel(2)->days(); // a device that was offline for two days still has to be told

        prune();

        expect(CaptureSession::onlyTrashed()->find($session->id))->not->toBeNull();
    });

    it('forgets an old tombstone for good', function () {
        $session = agedSession($this, 5);
        $session->delete();
        // Old by both measures: created and deleted well before the retention window.
        CaptureSession::withTrashed()->whereKey($session->id)->update(['created_at' => now()->subDays(60), 'deleted_at' => now()->subDays(60), 'media_erased_at' => now()->subDays(58)]);

        prune();

        expect(CaptureSession::withTrashed()->find($session->id))->toBeNull();
    });
});

describe('other records', function () {
    it('prunes old webhook events and keeps recent ones', function () {
        $old = WebhookEvent::query()->create(['provider' => 'assemblyai', 'idempotency_key' => 'old', 'status' => 'processed']);
        $new = WebhookEvent::query()->create(['provider' => 'assemblyai', 'idempotency_key' => 'new', 'status' => 'processed']);
        WebhookEvent::query()->whereKey($old->id)->update(['created_at' => now()->subDays(45)]);

        prune();

        expect(WebhookEvent::query()->pluck('idempotency_key')->all())->toBe(['new']);
    });

    it('leaves a young session\'s rows alone while pruning an old standalone recording', function () {
        $session = agedSession($this, 2);
        Recording::query()->where('capture_session_id', $session->id)->update(['created_at' => now()->subDays(50)]);
        $path = Recording::query()->where('capture_session_id', $session->id)->value('stored_path');

        prune();

        expect(CaptureSession::query()->find($session->id))->not->toBeNull()
            ->and(Recording::query()->where('capture_session_id', $session->id)->count())->toBe(0)
            ->and(Storage::disk('recordings')->exists($path))->toBeFalse();
    });

    it('cleans up the upload parts of an abandoned session', function () {
        $bytes = Fixtures::audio(6_000_000);
        [, $token] = $this->enroll();
        $created = $this->asToken($token)->createSession($bytes)->assertCreated();
        $this->postChunk($created->json('recordings.0.id'), 0, substr($bytes, 0, $created->json('recordings.0.upload.chunk_size_bytes')))->assertOk();
        expect(Storage::disk('recordings')->allFiles())->not->toBe([]);
        CaptureSession::query()->update(['created_at' => now()->subDays(40)]);
        Recording::query()->update(['created_at' => now()->subDays(40)]);

        prune();

        expect(Storage::disk('recordings')->allFiles())->toBe([])->and(Recording::query()->count())->toBe(0);
    });

    it('removes tokens that expired more than a day ago and keeps the rest', function () {
        [$device] = $this->enroll();
        $device->createToken('long expired', ['*'], now()->subDays(3));
        $device->createToken('just expired', ['*'], now()->subHours(2));
        $device->createToken('valid', ['*'], now()->addDays(30));
        $before = PersonalAccessToken::query()->count();

        Artisan::call('sanctum:prune-expired', ['--hours' => 24]);

        expect(PersonalAccessToken::query()->count())->toBe($before - 1)
            ->and(PersonalAccessToken::query()->where('name', 'long expired')->count())->toBe(0)
            ->and(PersonalAccessToken::query()->where('name', 'valid')->count())->toBe(1)
            ->and(PersonalAccessToken::query()->where('name', 'just expired')->count())->toBe(1);
    });
});

describe('scheduling', function () {
    it('schedules the queue worker, the reconciler and both daily clean-ups', function () {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        expect($output)->toContain('queue:work --stop-when-empty')->toContain('stt:reconcile')->toContain('model:prune')->toContain('sanctum:prune-expired');
    });
});

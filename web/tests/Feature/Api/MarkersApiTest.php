<?php

declare(strict_types=1);

use App\Filament\Pages\Settings;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Models\CaptureSession;
use App\Models\SessionMarker;
use App\Services\SessionMarkers;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/*
 * docs/API.md "Bookmarks and pauses" (since v1.1): markers and pauses sent with a recording, the marker endpoints,
 * the library tag filters, the tag presets in the config and the console.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->bytes = Fixtures::audio(3000);
});

/** @return array<string, mixed> */
function markersBody(): array
{
    return [
        'markers' => [
            ['at_ms' => 12000, 'tag' => '  Budget ', 'note' => 'Max   5k'],
            ['at_ms' => 3000, 'tag' => null, 'note' => null],
        ],
        'pauses' => [['at_ms' => 20000, 'paused_ms' => 45000]],
    ];
}

describe('markers sent with a recording', function () {
    it('stores markers and pauses from the one-shot create', function () {
        $id = (string) $this->createSession($this->bytes, markersBody())->assertSuccessful()->json('session.id');

        $detail = $this->getJson("/api/sessions/{$id}")->assertOk();
        expect($detail->json('markers'))->toHaveCount(2)
            ->and($detail->json('markers.0.at_ms'))->toBe(3000)
            ->and($detail->json('markers.1.tag'))->toBe('Budget')
            ->and($detail->json('markers.1.note'))->toBe('Max 5k')
            ->and($detail->json('markers.1.source'))->toBe('device')
            ->and($detail->json('pauses'))->toBe([['at_ms' => 20000, 'paused_ms' => 45000]]);
    });

    it('does not duplicate markers when the same create is repeated', function () {
        $body = Fixtures::sessionBody($this->bytes, array_merge(['client_ref' => 'rep-1'], markersBody()));
        $this->postJson('/api/sessions', $body)->assertSuccessful();
        $this->postJson('/api/sessions', $body)->assertSuccessful();

        expect(SessionMarker::query()->count())->toBe(2);
    });

    it('stores markers sent with finish once', function () {
        $id = $this->openSession();
        $body = Fixtures::finishBody($this->bytes, markersBody());
        $this->postJson("/api/sessions/{$id}/finish", $body)->assertOk();
        $this->postJson("/api/sessions/{$id}/finish", $body)->assertOk();

        $session = CaptureSession::query()->findOrFail($id);
        expect($session->markers)->toHaveCount(2)
            ->and($session->pauses)->toBe([['at_ms' => 20000, 'paused_ms' => 45000]]);
    });

    it('answers empty lists for a recording without them', function () {
        $id = (string) $this->createSession($this->bytes)->assertSuccessful()->json('session.id');

        $detail = $this->getJson("/api/sessions/{$id}")->assertOk();
        expect($detail->json('markers'))->toBe([])
            ->and($detail->json('pauses'))->toBe([]);
    });

    it('rejects malformed markers and pauses', function (array $extra, string $field) {
        $this->createSession($this->bytes, $extra)->assertUnprocessable()->assertJsonValidationErrors([$field]);
        expect(CaptureSession::query()->count())->toBe(0);
    })->with([
        'tag too long' => [['markers' => [['at_ms' => 1, 'tag' => str_repeat('t', 41)]]], 'markers.0.tag'],
        'note too long' => [['markers' => [['at_ms' => 1, 'note' => str_repeat('n', 201)]]], 'markers.0.note'],
        'negative time' => [['markers' => [['at_ms' => -1]]], 'markers.0.at_ms'],
        'missing time' => [['markers' => [['tag' => 'Budget']]], 'markers.0.at_ms'],
        'too many markers' => [['markers' => array_fill(0, SessionMarkers::MAX_MARKERS + 1, ['at_ms' => 1])], 'markers'],
        'zero pause' => [['pauses' => [['at_ms' => 1, 'paused_ms' => 0]]], 'pauses.0.paused_ms'],
        'too many pauses' => [['pauses' => array_fill(0, SessionMarkers::MAX_PAUSES + 1, ['at_ms' => 1, 'paused_ms' => 5])], 'pauses'],
    ]);
});

describe('the marker endpoints', function () {
    it('adds a bookmark later and touches the session for sync', function () {
        $session = $this->makeReadySession($this->device, $this->bytes);
        $this->travel(5)->minutes();

        $created = $this->postJson("/api/sessions/{$session->id}/markers", ['at_ms' => 9000, 'tag' => 'Deadline'])->assertCreated();

        expect($created->json('tag'))->toBe('Deadline')
            ->and($created->json('at_ms'))->toBe(9000)
            ->and($created->json('id'))->toBeString()
            ->and($session->fresh()->updated_at->greaterThan($session->updated_at))->toBeTrue();
    });

    it('deletes a bookmark and answers 404 for an unknown one', function () {
        $session = $this->makeReadySession($this->device, $this->bytes);
        $id = (string) $this->postJson("/api/sessions/{$session->id}/markers", ['at_ms' => 9000])->assertCreated()->json('id');

        $this->deleteJson("/api/sessions/{$session->id}/markers/{$id}")->assertNoContent();
        $this->deleteJson("/api/sessions/{$session->id}/markers/{$id}")->assertNotFound()->assertJsonPath('code', 'marker_not_found');
        expect(SessionMarker::query()->count())->toBe(0);
    });

    it('hides another owner\'s session', function () {
        [$other] = $this->enroll();
        $session = $this->makeReadySession($other, $this->bytes);
        $marker = $session->markers()->create(['at_ms' => 1, 'source' => 'device']);

        $this->postJson("/api/sessions/{$session->id}/markers", ['at_ms' => 1])->assertNotFound();
        $this->deleteJson("/api/sessions/{$session->id}/markers/{$marker->id}")->assertNotFound();
        expect(SessionMarker::query()->count())->toBe(1);
    });

    it('refuses a bookmark past the limit', function () {
        $session = $this->makeReadySession($this->device, $this->bytes);
        $session->markers()->createMany(array_fill(0, SessionMarkers::MAX_MARKERS, ['at_ms' => 1, 'source' => 'device']));

        $this->postJson("/api/sessions/{$session->id}/markers", ['at_ms' => 2])->assertStatus(409)->assertJsonPath('code', 'too_many_markers');
    });

    it('validates a single bookmark', function () {
        $session = $this->makeReadySession($this->device, $this->bytes);

        $this->postJson("/api/sessions/{$session->id}/markers", ['at_ms' => -5])->assertUnprocessable()->assertJsonValidationErrors(['at_ms']);
        $this->postJson("/api/sessions/{$session->id}/markers", ['at_ms' => 5, 'tag' => str_repeat('x', 41)])->assertUnprocessable()->assertJsonValidationErrors(['tag']);
    });
});

describe('the library', function () {
    it('lists marker counts and tags and filters by tag and bookmarked', function () {
        $tagged = $this->makeReadySession($this->device, $this->bytes, ['client_ref' => 'tagged']);
        $tagged->markers()->createMany([
            ['at_ms' => 1, 'tag' => 'Budget', 'source' => 'device'],
            ['at_ms' => 2, 'tag' => 'Budget', 'source' => 'device'],
            ['at_ms' => 3, 'tag' => null, 'source' => 'device'],
        ]);
        $plain = $this->makeReadySession($this->device, $this->bytes, ['client_ref' => 'plain']);
        $untagged = $this->makeReadySession($this->device, $this->bytes, ['client_ref' => 'untagged']);
        $untagged->markers()->create(['at_ms' => 1, 'source' => 'device']);

        $all = collect($this->getJson('/api/library')->assertOk()->json('data'))->keyBy('id');
        expect($all[$tagged->id]['marker_count'])->toBe(3)
            ->and($all[$tagged->id]['tags'])->toBe(['Budget'])
            ->and($all[$plain->id]['marker_count'])->toBe(0)
            ->and($all[$plain->id]['tags'])->toBe([]);

        $byTag = collect($this->getJson('/api/library?tag=Budget')->assertOk()->json('data'))->pluck('id')->all();
        $bookmarked = collect($this->getJson('/api/library?bookmarked=1')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        expect($byTag)->toBe([$tagged->id])
            ->and($bookmarked)->toBe(collect([$tagged->id, $untagged->id])->sort()->values()->all());
        $this->getJson('/api/library?tag='.str_repeat('x', 41))->assertUnprocessable();
    });
});

describe('tag presets', function () {
    it('sends the default presets in the config', function () {
        $this->getJson('/api/config')->assertOk()->assertJsonPath('markers.tags', SessionMarkers::DEFAULT_TAGS);
    });

    it('cleans the presets and falls back to the defaults', function () {
        expect(SessionMarkers::presets([' Scope ', 'Scope', '', str_repeat('x', 41), 'Risk']))->toBe(['Scope', 'Risk'])
            ->and(SessionMarkers::presets([]))->toBe(SessionMarkers::DEFAULT_TAGS)
            ->and(SessionMarkers::presets('broken'))->toBe(SessionMarkers::DEFAULT_TAGS)
            ->and(SessionMarkers::presets(array_map(fn (int $i) => "Tag {$i}", range(1, 20))))->toHaveCount(12);
    });

    it('saves the presets from the console settings page into the config', function () {
        Filament::setCurrentPanel('admin');
        $this->signInAsAdmin();

        $this->panelComponent(Settings::class)
            ->fillForm(['marker_tags' => ['Scope', ' Risk ', 'Scope']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(SettingsRepository::class)->markerTags())->toBe(['Scope', 'Risk']);
    });
});

describe('the console', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
    });

    it('shows bookmarks on the session page', function () {
        $admin = $this->signInAsAdmin();
        [$device] = $this->enroll($admin);
        $session = $this->makeReadySession($device, $this->bytes);
        $session->markers()->create(['at_ms' => 65000, 'tag' => 'Requirement', 'note' => 'Needs SSO', 'source' => 'device']);

        $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
            ->assertSuccessful()
            ->assertSee('Requirement')
            ->assertSee('Needs SSO');
    });

    it('filters the sessions list by tag and bookmarked', function () {
        $admin = $this->signInAsAdmin();
        [$device] = $this->enroll($admin);
        $tagged = $this->makeReadySession($device, $this->bytes, ['client_ref' => 'a']);
        $tagged->markers()->create(['at_ms' => 1, 'tag' => 'Budget', 'source' => 'device']);
        $plain = $this->makeReadySession($device, $this->bytes, ['client_ref' => 'b']);

        $this->panelComponent(ListCaptureSessions::class)
            ->filterTable('tag', 'Budget')
            ->assertCanSeeTableRecords([$tagged])
            ->assertCanNotSeeTableRecords([$plain]);
        $this->panelComponent(ListCaptureSessions::class)
            ->filterTable('bookmarked')
            ->assertCanSeeTableRecords([$tagged])
            ->assertCanNotSeeTableRecords([$plain]);
    });
});

it('rolls the markers migration back and forth', function () {
    $migration = 'database/migrations/2026_10_26_000001_create_session_markers_table.php';
    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('session_markers'))->toBeFalse()
        ->and(Schema::hasColumn('capture_sessions', 'pauses'))->toBeFalse();

    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('session_markers'))->toBeTrue()
        ->and(Schema::hasColumn('capture_sessions', 'pauses'))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->token = $token;
    $this->owner = $this->device->user;
    $this->asToken($token);
});

/** Create a session of $owner whose updated_at is $minutesAgo minutes in the past. */
function sessionAged(User $owner, int $minutesAgo, array $attributes = []): CaptureSession
{
    $at = now()->subMinutes($minutesAgo);

    return CaptureSession::factory()->create(array_merge(['user_id' => $owner->id, 'created_at' => $at, 'updated_at' => $at], $attributes));
}

/** The client-side merge both apps implement (docs/API.md "Library & sync"). */
function applyLibraryPage(array $local, array $incoming): array
{
    foreach ($incoming as $item) {
        if ($item['deleted'] ?? false) {
            unset($local[$item['id']]);

            continue;
        }
        if (! isset($local[$item['id']]) || $local[$item['id']]['updated_at'] <= $item['updated_at']) {
            $local[$item['id']] = $item;
        }
    }

    return $local;
}

describe('paging', function () {
    it('walks the whole library newest change first, once each, with a cursor between pages', function () {
        $expected = [];
        foreach (range(1, 7) as $i) {
            $expected[] = sessionAged($this->owner, 100 - $i * 10)->id; // later $i = more recent
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->getJson('/api/library?limit=3'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
            $seen = array_merge($seen, array_column($page->json('data'), 'id'));
            $cursor = $page->json('next_cursor');
            $pages++;
            expect($pages)->toBeLessThan(10);
        } while ($cursor !== null);

        expect($pages)->toBe(3)->and($seen)->toBe(array_reverse($expected));
    });

    it('orders by modification time, so a newly touched old session surfaces first', function () {
        $old = sessionAged($this->owner, 500);
        $new = sessionAged($this->owner, 5);
        $old->forceFill(['updated_at' => now()])->save();

        expect(array_column($this->getJson('/api/library')->json('data'), 'id'))->toBe([$old->id, $new->id]);
    });

    it('breaks ties between equal timestamps by id so no page repeats or skips an item', function () {
        $at = now()->subMinute();
        foreach (range(1, 6) as $_) {
            CaptureSession::factory()->create(['user_id' => $this->owner->id, 'created_at' => $at, 'updated_at' => $at]);
        }

        $seen = [];
        $cursor = null;
        do {
            $page = $this->getJson('/api/library?limit=2'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
            $seen = array_merge($seen, array_column($page->json('data'), 'id'));
            $cursor = $page->json('next_cursor');
        } while ($cursor !== null);

        expect($seen)->toHaveCount(6)->and(array_unique($seen))->toHaveCount(6);
    });

    it('returns fifty items by default and says where the rest begins', function () {
        foreach (range(1, 55) as $i) {
            sessionAged($this->owner, $i);
        }

        $page = $this->getJson('/api/library')->assertOk();

        expect($page->json('data'))->toHaveCount(50)->and($page->json('next_cursor'))->not->toBeNull();
        $second = $this->getJson('/api/library?cursor='.urlencode($page->json('next_cursor')))->assertOk();
        expect($second->json('data'))->toHaveCount(5)->and($second->json('next_cursor'))->toBeNull();
    });

    it('reports no cursor when everything fits on one page', function () {
        sessionAged($this->owner, 1);

        $this->getJson('/api/library?limit=1')->assertOk()->assertJsonPath('next_cursor', null);
    });

    it('returns an empty page for an empty library', function () {
        $this->getJson('/api/library')->assertOk()->assertJsonPath('data', [])->assertJsonPath('next_cursor', null);
    });

    it('enforces the limit bounds', function (mixed $limit, int $status) {
        sessionAged($this->owner, 1);

        $this->getJson('/api/library?limit='.$limit)->assertStatus($status);
    })->with([
        'zero' => [0, 422],
        'negative' => [-5, 422],
        'one' => [1, 200],
        'one hundred' => [100, 200],
        'one hundred and one' => [101, 422],
        'text' => ['many', 422],
        'fraction' => ['2.5', 422],
    ]);

    it('does not fail on an invalid cursor', function (string $cursor) {
        sessionAged($this->owner, 1);

        expect($this->getJson('/api/library?cursor='.urlencode($cursor))->getStatusCode())->toBeLessThan(500);
    })->with(['garbage', '%%%%', 'eyJ1cGRhdGVkX2F0IjoiYmFkIn0', str_repeat('A', 600)]);

    it('does not fail on a well-formed cursor that carries odd values', function (array $parameters) {
        sessionAged($this->owner, 1);
        $stranger = sessionAged(User::factory()->create(), 1, ['title' => 'Someone else']);
        $cursor = rtrim(strtr(base64_encode(json_encode($parameters, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $response = $this->getJson('/api/library?cursor='.$cursor);

        expect($response->getStatusCode())->toBeLessThan(500);
        expect($response->getContent())->not->toContain($stranger->id)->not->toContain('Someone else');
    })->with([
        'empty' => [[]],
        'unrelated keys' => [['foo' => 'bar']],
        'only a timestamp' => [['updated_at' => '2026-01-01 00:00:00']],
        'sql in the values' => [['updated_at' => "x' OR 1=1 --", 'id' => "1' OR '1'='1", '_pointsToNextItems' => true]],
        'arrays' => [['updated_at' => ['a'], 'id' => ['b'], '_pointsToNextItems' => true]],
        'huge numbers' => [['updated_at' => PHP_INT_MAX, 'id' => PHP_INT_MAX, '_pointsToNextItems' => true]],
        'a non-boolean direction' => [['updated_at' => '2026-01-01', 'id' => 'x', '_pointsToNextItems' => 'yes']],
    ]);

    it('does not fail on a well-formed cursor whose fields are null', function (array $parameters) {
        sessionAged($this->owner, 1);
        $stranger = sessionAged(User::factory()->create(), 1, ['title' => 'Someone else']);
        $cursor = rtrim(strtr(base64_encode(json_encode($parameters, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $response = $this->getJson('/api/library?cursor='.$cursor);

        expect($response->getStatusCode())->toBeLessThan(500);
        expect($response->getContent())->not->toContain($stranger->id)->not->toContain('Someone else');
    })->with([
        'nulls' => [['updated_at' => null, 'id' => null, '_pointsToNextItems' => false]],
    ]);

    it('keeps the number of queries flat as the library grows', function () {
        foreach (range(1, 30) as $i) {
            $session = sessionAged($this->owner, $i, ['client_id' => Client::factory()->create(['user_id' => $this->owner->id])->id]);
            $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => "text {$i}"]);
        }
        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->asToken($this->token)->getJson('/api/library?limit=30')->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThan(12);
    });
});

describe('incremental sync', function () {
    it('returns only items changed at or after since, treating the boundary as inclusive', function () {
        $this->freezeTime();
        $before = sessionAged($this->owner, 30);
        $atBoundary = sessionAged($this->owner, 10);
        $after = sessionAged($this->owner, 1);
        $since = now()->subMinutes(10)->toAtomString();

        $ids = array_column($this->getJson('/api/library?since='.urlencode($since))->json('data'), 'id');

        expect($ids)->toBe([$after->id, $atBoundary->id])->not->toContain($before->id);
    });

    it('takes server_time when the query starts, so a change during the request is not skipped by the next sync', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:10'));
        $first = $this->getJson('/api/library')->assertOk();
        // A change in the same second as the sync, written after the answer was built.
        $session = sessionAged($this->owner, 0);

        $next = $this->getJson('/api/library?since='.urlencode($first->json('server_time')))->assertOk();

        expect($first->json('server_time'))->toBe('2026-10-24T12:00:10+00:00')
            ->and(array_column($next->json('data'), 'id'))->toBe([$session->id]);
    });

    it('delivers a final transcript that completes or fails after the last sync', function (string $status) {
        $this->freezeTime();
        $session = sessionAged($this->owner, 10, ['status' => SessionStatus::Processing]);
        $final = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Submitted]);
        $this->travel(1)->minutes();
        $first = $this->getJson('/api/library')->assertOk();

        $this->travel(2)->minutes();
        $final->update(['status' => $status]); // only the transcript row changes
        $this->travel(1)->seconds();

        $second = $this->getJson('/api/library?since='.urlencode($first->json('server_time')))->assertOk();
        expect(array_column($second->json('data'), 'id'))->toBe([$session->id])
            ->and($second->json('data.0.transcript.final_status'))->toBe($status);
    })->with(['completed', 'failed']);

    it('includes a tombstone for every session deleted since', function () {
        $this->freezeTime();
        $kept = sessionAged($this->owner, 60);
        $gone = sessionAged($this->owner, 60);
        $this->travel(5)->minutes();
        $since = now()->toAtomString();
        $this->travel(1)->minutes();
        $gone->delete();

        $data = collect($this->getJson('/api/library?since='.urlencode($since))->assertOk()->json('data'));

        expect($data->pluck('id')->all())->toBe([$gone->id])->not->toContain($kept->id);
    });

    it('shapes a tombstone as exactly id, client_ref, deleted and updated_at and leaks nothing else', function () {
        $session = sessionAged($this->owner, 10, ['title' => 'Confidential merger call', 'client_ref' => 'ref-tomb']);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'secret words']);
        $session->delete();

        $response = $this->getJson('/api/library?since='.urlencode(now()->subHour()->toAtomString()))->assertOk();
        $tombstone = $response->json('data.0');

        expect(array_keys($tombstone))->toEqualCanonicalizing(['id', 'client_ref', 'deleted', 'updated_at'])
            ->and($tombstone['deleted'])->toBeTrue()->and($tombstone['client_ref'])->toBe('ref-tomb');
        expect($response->getContent())->not->toContain('Confidential')->not->toContain('secret words');
    });

    it('omits tombstones when no since is given', function () {
        $live = sessionAged($this->owner, 5);
        sessionAged($this->owner, 5)->delete();

        expect(array_column($this->getJson('/api/library')->json('data'), 'id'))->toBe([$live->id]);
    });

    it('does not return the same tombstone again once the client has moved its since forward', function () {
        $session = sessionAged($this->owner, 5);
        $session->delete();
        // Sync well after the deletion: changes made in the same second as a sync may be delivered again.
        $this->travel(2)->seconds();
        $first = $this->getJson('/api/library?since='.urlencode(now()->subHour()->toAtomString()))->assertOk();
        expect($first->json('data'))->toHaveCount(1);

        $this->travel(2)->seconds();
        $second = $this->getJson('/api/library?since='.urlencode($first->json('server_time')))->assertOk();

        expect($second->json('data'))->toBe([]);
    });

    it('returns a session again when it changes after the last sync', function () {
        $this->freezeTime();
        $session = sessionAged($this->owner, 10);
        $first = $this->getJson('/api/library?since='.urlencode(now()->subHour()->toAtomString()))->assertOk();

        $this->travel(30)->seconds();
        $session->update(['status' => SessionStatus::Failed]);
        $this->travel(1)->seconds();
        $second = $this->getJson('/api/library?since='.urlencode($first->json('server_time')))->assertOk();

        expect(array_column($second->json('data'), 'id'))->toBe([$session->id])->and($second->json('data.0.status'))->toBe('failed');
    });

    it('accepts ISO-8601 with Z, with an offset, with fractions and as a bare date', function (string $since) {
        sessionAged($this->owner, 60 * 24 * 3);
        $recent = sessionAged($this->owner, 1);

        $response = $this->getJson('/api/library?since='.urlencode($since))->assertOk();

        expect(array_column($response->json('data'), 'id'))->toContain($recent->id);
    })->with([
        'Z suffix' => [fn () => now()->subDay()->utc()->format('Y-m-d\TH:i:s\Z')],
        'numeric zero offset' => [fn () => now()->subDay()->utc()->format('Y-m-d\TH:i:sP')],
        'fractional seconds' => [fn () => now()->subDay()->utc()->format('Y-m-d\TH:i:s.u\Z')],
        'date only' => [fn () => now()->subDay()->format('Y-m-d')],
    ]);

    it('rejects a since that is not a date', function (string $since) {
        $this->getJson('/api/library?since='.urlencode($since))->assertStatus(422)->assertJsonValidationErrors(['since']);
    })->with(['yesterday-ish', 'tomorrow?!', '2026-99-99', '12:00:xx', 'null']);

    it('treats a since carrying a UTC offset as the instant it names', function () {
        $this->freezeTime();
        $session = sessionAged($this->owner, 60); // changed one hour ago
        // One hour and 30 minutes ago, written in a +05:00 wall clock.
        $since = now()->subMinutes(90)->setTimezone('Asia/Karachi')->format('Y-m-d\TH:i:sP');

        $ids = array_column($this->getJson('/api/library?since='.urlencode($since))->json('data'), 'id');

        expect($ids)->toContain($session->id);
    });

    it('returns the server time in the same format as every updated_at, so string comparison is safe', function () {
        sessionAged($this->owner, 5);
        sessionAged($this->owner, 3)->delete();

        $page = $this->getJson('/api/library?since='.urlencode(now()->subDay()->toAtomString()))->assertOk();

        expect($page->json('server_time'))->toMatch(Fixtures::ISO_8601);
        foreach ($page->json('data') as $item) {
            expect($item['updated_at'])->toMatch(Fixtures::ISO_8601)->toEndWith('+00:00');
        }
        expect(strlen($page->json('server_time')))->toBe(strlen($page->json('data.0.updated_at')));
    });

    it('delivers a change made within the same second as the previous sync on the next one', function () {
        $session = sessionAged($this->owner, 5);
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00.400'));
        $first = $this->getJson('/api/library')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00.800'));
        $session->forceFill(['title' => 'Edited after the sync'])->save();

        $next = $this->getJson('/api/library?since='.urlencode($first->json('server_time')))->assertOk();

        // The device stores server_time as its next "since"; a change 400 ms after that sync must not be lost.
        expect(array_column($next->json('data'), 'id'))->toBe([$session->id]);
    });

    it('reports a server time that is never in the future and never goes backwards between pages', function () {
        foreach (range(1, 4) as $i) {
            sessionAged($this->owner, $i);
        }
        $first = $this->getJson('/api/library?limit=2')->assertOk();

        $second = $this->getJson('/api/library?limit=2&cursor='.urlencode($first->json('next_cursor')))->assertOk();

        expect(strtotime($first->json('server_time')))->toBeLessThanOrEqual(time())->and(strtotime($second->json('server_time')))->toBeGreaterThanOrEqual(strtotime($first->json('server_time')));
    });

    it('lets two devices converge through the since/tombstone protocol', function () {
        $this->freezeTime();
        [$tablet, $tabletToken] = $this->enroll($this->owner, platform: 'windows');
        $local = [];
        $since = null;
        $sync = function () use (&$local, &$since, $tabletToken) {
            $cursor = null;
            $serverTime = null;
            do {
                $url = '/api/library?limit=2'.($since ? '&since='.urlencode($since) : '').($cursor ? '&cursor='.urlencode($cursor) : '');
                $page = $this->asToken($tabletToken)->getJson($url)->assertOk();
                $serverTime ??= $page->json('server_time');
                $local = applyLibraryPage($local, $page->json('data'));
                $cursor = $page->json('next_cursor');
            } while ($cursor !== null);
            $since = $serverTime;
        };

        $a = sessionAged($this->owner, 50);
        $b = sessionAged($this->owner, 40);
        $c = sessionAged($this->owner, 30);
        $this->travel(1)->seconds();
        $sync();
        expect(array_keys($local))->toEqualCanonicalizing([$a->id, $b->id, $c->id]);

        $this->travel(5)->seconds();
        $b->delete();
        $d = sessionAged($this->owner, 0);
        $c->update(['status' => SessionStatus::Failed]);
        $this->travel(5)->seconds();
        $sync();

        expect(array_keys($local))->toEqualCanonicalizing([$a->id, $c->id, $d->id])->and($local[$c->id]['status'])->toBe('failed');

        $this->travel(5)->seconds();
        $this->asToken($this->token);
        $sync();
        expect(array_keys($local))->toEqualCanonicalizing([$a->id, $c->id, $d->id]);
    });

    it('delivers a live transcript added after the last sync to the other devices', function () {
        $this->freezeTime();
        $session = sessionAged($this->owner, 10, ['device_id' => $this->device->id, 'duration_ms' => 60000]);
        $first = $this->getJson('/api/library')->assertOk();
        expect($first->json('data.0.transcript.live_available'))->toBeFalse();

        $this->travel(2)->minutes();
        $this->postJson("/api/sessions/{$session->id}/transcripts", [
            'kind' => 'live', 'provider' => 'assemblyai', 'segments' => [Fixtures::segment()],
        ])->assertCreated();
        $this->travel(1)->seconds();

        $second = $this->getJson('/api/library?since='.urlencode($first->json('server_time')))->assertOk();
        expect(array_column($second->json('data'), 'id'))->toBe([$session->id])
            ->and($second->json('data.0.transcript.live_available'))->toBeTrue();
    });
});

describe('filters', function () {
    beforeEach(function () {
        $this->phoneWhatsapp = sessionAged($this->owner, 6, ['source_device' => SourceDevice::Phone, 'source_app' => SourceApp::WhatsApp]);
        $this->desktopZoom = sessionAged($this->owner, 5, ['source_device' => SourceDevice::Desktop, 'source_app' => SourceApp::Zoom]);
        $this->webNote = sessionAged($this->owner, 4, ['source_device' => SourceDevice::Web, 'source_app' => SourceApp::VoiceNote, 'status' => SessionStatus::Failed]);
        $this->client = Client::factory()->create(['user_id' => $this->owner->id]);
        $this->withClient = sessionAged($this->owner, 3, ['client_id' => $this->client->id, 'source_device' => SourceDevice::Phone, 'source_app' => SourceApp::Other]);
    });

    it('filters by one or several source devices', function (string $query, array $expected) {
        $ids = array_column($this->getJson('/api/library?source='.urlencode($query))->assertOk()->json('data'), 'id');

        expect($ids)->toEqualCanonicalizing(array_map(fn (string $name) => $this->{$name}->id, $expected));
    })->with([
        'phone' => ['phone', ['phoneWhatsapp', 'withClient']],
        'desktop' => ['desktop', ['desktopZoom']],
        'web' => ['web', ['webNote']],
        'phone and desktop' => ['phone,desktop', ['phoneWhatsapp', 'withClient', 'desktopZoom']],
        'with spaces' => [' phone , web ', ['phoneWhatsapp', 'withClient', 'webNote']],
        'unknown name ignored beside a valid one' => ['phone,bogus', ['phoneWhatsapp', 'withClient']],
        'only unknown names matches nothing' => ['bogus', []],
        'case sensitive' => ['Phone', []],
    ]);

    it('filters by source app, client and status', function () {
        expect(array_column($this->getJson('/api/library?source_app=zoom')->json('data'), 'id'))->toBe([$this->desktopZoom->id]);
        expect(array_column($this->getJson('/api/library?client_id='.$this->client->id)->json('data'), 'id'))->toBe([$this->withClient->id]);
        expect(array_column($this->getJson('/api/library?status=failed')->json('data'), 'id'))->toBe([$this->webNote->id]);
    });

    it('combines filters conjunctively', function () {
        $ids = array_column($this->getJson('/api/library?source=phone&source_app=whatsapp&status=completed')->json('data'), 'id');

        expect($ids)->toBe([$this->phoneWhatsapp->id]);
        expect($this->getJson('/api/library?source=desktop&source_app=whatsapp')->json('data'))->toBe([]);
    });

    it('rejects values outside the enumerations', function (string $query, string $field) {
        $this->getJson('/api/library?'.$query)->assertStatus(422)->assertJsonValidationErrors([$field]);
    })->with([
        ['source_app=telegram', 'source_app'],
        ['source_app=Zoom', 'source_app'],
        ['status=done', 'status'],
        ['client_id=short', 'client_id'],
    ]);

    it('applies filters to tombstones too, so a filtered view is not told to delete items it never held', function () {
        $this->desktopZoom->delete();
        $since = urlencode(now()->subHour()->toAtomString());

        expect(array_column($this->getJson("/api/library?since={$since}&source=phone")->json('data'), 'id'))->not->toContain($this->desktopZoom->id);
        expect(array_column($this->getJson("/api/library?since={$since}&source=desktop")->json('data'), 'id'))->toBe([$this->desktopZoom->id]);
    });

    it('finds nothing for a client of another owner', function () {
        $stranger = Client::factory()->create();
        CaptureSession::factory()->create(['user_id' => $stranger->user_id, 'client_id' => $stranger->id]);

        $this->getJson('/api/library?client_id='.$stranger->id)->assertOk()->assertJsonPath('data', []);
    });
});

describe('isolation', function () {
    it('never shows another owner\'s sessions, tombstones or counts', function () {
        $mine = sessionAged($this->owner, 5);
        $stranger = User::factory()->create();
        $theirs = sessionAged($stranger, 5, ['title' => 'Their private call']);
        $theirsGone = sessionAged($stranger, 5, ['title' => 'Their deleted call']);
        $theirsGone->delete();

        $plain = $this->getJson('/api/library')->assertOk();
        $incremental = $this->getJson('/api/library?since='.urlencode(now()->subYear()->toAtomString()))->assertOk();

        expect(array_column($plain->json('data'), 'id'))->toBe([$mine->id]);
        expect(array_column($incremental->json('data'), 'id'))->toBe([$mine->id]);
        expect($plain->getContent().$incremental->getContent())->not->toContain($theirs->id)->not->toContain($theirsGone->id)->not->toContain('Their');
    });

    it('shows sessions of all of the owner\'s devices', function () {
        [$laptop] = $this->enroll($this->owner, platform: 'windows');
        $fromPhone = sessionAged($this->owner, 5, ['device_id' => $this->device->id, 'source_device' => SourceDevice::Phone]);
        $fromLaptop = sessionAged($this->owner, 4, ['device_id' => $laptop->id, 'source_device' => SourceDevice::Desktop]);

        $data = collect($this->getJson('/api/library')->json('data'))->keyBy('id');

        expect($data->keys()->all())->toEqualCanonicalizing([$fromPhone->id, $fromLaptop->id])
            ->and($data[$fromLaptop->id]['device']['name'])->toBe($laptop->name)
            ->and($data[$fromLaptop->id]['source_device'])->toBe('desktop');
    });

    it('requires the library:read ability', function () {
        [, $limited] = $this->enroll($this->owner, ['config:read']);

        $this->asToken($limited)->getJson('/api/library')->assertForbidden();
    });
});

describe('item contents', function () {
    it('summarises the transcripts without sending their bodies', function () {
        $session = sessionAged($this->owner, 5, ['title' => 'Quarterly review']);
        $long = str_repeat('word ', 80);
        $final = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => $long, 'segments' => [['channel' => 1]]]);
        $live = $session->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'live text']);

        $item = $this->getJson('/api/library')->json('data.0');

        expect($item['transcript'])->toBe([
            'final_status' => 'completed',
            'final_transcript_id' => $final->id,
            'live_available' => true,
            'live_transcript_id' => $live->id,
            'preview' => mb_strimwidth(trim($long), 0, 160, '…'),
            'final_language' => null,
            'final_provider' => 'assemblyai',
            'final_provider_label' => 'AssemblyAI',
            'final_model' => null,
            'live_language' => null,
            'script_warning' => null,
            'final_error_code' => null,
            'alternate_transcript_id' => null,
            'alternate_status' => null,
            'alternate_language' => null,
            'final_progress' => null,
        ]);
        expect(mb_strlen($item['transcript']['preview']))->toBeLessThanOrEqual(160)->and($item)->not->toHaveKey('segments');
    });

    it('prefers the final preview, falls back to live, and is null when there is none', function () {
        $only = sessionAged($this->owner, 3);
        $liveOnly = sessionAged($this->owner, 2);
        $liveOnly->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'captions only']);
        $pendingFinal = sessionAged($this->owner, 1);
        $pendingFinal->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Submitted]);
        $pendingFinal->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'live while waiting']);

        $byId = collect($this->getJson('/api/library')->json('data'))->keyBy('id');

        expect($byId[$only->id]['transcript']['preview'])->toBeNull()
            ->and($byId[$liveOnly->id]['transcript']['preview'])->toBe('captions only')
            ->and($byId[$pendingFinal->id]['transcript']['preview'])->toBe('live while waiting')
            ->and($byId[$pendingFinal->id]['transcript']['final_status'])->toBe('submitted');
    });

    it('labels every source device and app with text and an icon key', function () {
        foreach (SourceDevice::cases() as $i => $device) {
            sessionAged($this->owner, 10 + $i, ['source_device' => $device, 'source_app' => SourceApp::cases()[$i]]);
        }

        foreach ($this->getJson('/api/library')->json('data') as $item) {
            expect($item['source_device_label'])->toBeString()->not->toBeEmpty()
                ->and($item['source_device_icon'])->toBeIn(['device-phone-mobile', 'computer-desktop', 'globe-alt'])
                ->and($item['source_app_label'])->toBeString()->not->toBeEmpty();
        }
    });

    it('serialises the fields the device apps read with the types they expect', function () {
        $client = Client::factory()->create(['user_id' => $this->owner->id, 'name' => 'Acme']);
        $session = sessionAged($this->owner, 5, ['device_id' => $this->device->id, 'client_id' => $client->id, 'duration_ms' => 61000]);

        $item = $this->getJson('/api/library')->json('data.0');

        // Kotlin LibraryItem: id, updated_at required; the rest optional with these types.
        expect($item['id'])->toBe($session->id)->toBeString()
            ->and($item['client_ref'])->toBeString()
            ->and($item['deleted'])->toBeBool()
            ->and($item['source_device'])->toBeString()
            ->and($item['source_device_label'])->toBeString()
            ->and($item['source_app'])->toBeString()
            ->and($item['source_app_label'])->toBeString()
            ->and($item['device'])->toBe(['id' => $this->device->id, 'name' => $this->device->name])
            ->and($item['client'])->toBe(['id' => $client->id, 'name' => 'Acme', 'color' => $client->color, 'avatar' => null])
            ->and($item['title'])->toBeString()
            ->and($item['started_at'])->toBeString()->toMatch(Fixtures::ISO_8601)
            ->and($item['duration_ms'])->toBeInt()->toBe(61000)
            ->and($item['status'])->toBeString()
            ->and($item['updated_at'])->toBeString()->toMatch(Fixtures::ISO_8601);
        // Kotlin TranscriptSummary
        expect($item['transcript']['live_available'])->toBeBool()
            ->and(array_key_exists('final_status', $item['transcript']))->toBeTrue();
    });
});

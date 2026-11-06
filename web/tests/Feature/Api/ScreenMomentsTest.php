<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Screen;
use App\Models\User;
use App\Services\Screens\ScreenStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/Support/screen_helpers.php';

/*
 * docs/API.md "Screen moments (since v1.5)": POST / GET / DELETE /api/sessions/{id}/screens, the signed picture links,
 * a project's Screens tab (GET /api/clients/{id}/screens), screen_count and the project `screens` flag.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->session = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => SessionStatus::Completed, 'duration_ms' => 900_000]);
});

/** @param  array<string, mixed>  $fields */
function sendScreen(object $test, string $sessionId, string $bytes, array $fields = [], string $name = 'screen.webp'): TestResponse
{
    return $test->post("/api/sessions/{$sessionId}/screens", [
        'image' => UploadedFile::fake()->createWithContent($name, $bytes),
        'at_ms' => 872000, 'width' => 1920, 'height' => 1080, 'trigger' => 'change', 'sha256' => hash('sha256', $bytes), ...$fields,
    ], ['Accept' => 'application/json']);
}

/** Rows only (no files): enough for lists, numbers and the cap. */
function screenRows(CaptureSession $session, int $count, int $fromMs = 0): void
{
    $now = now();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = ['id' => (string) Str::ulid(), 'workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => $fromMs + $i * 1000,
            'width' => 1920, 'height' => 1080, 'trigger' => 'change', 'sha256' => hash('sha256', $session->id.$fromMs.$i), 'mime' => 'image/webp', 'bytes' => 10,
            'image_path' => 'screens/none/'.$i.'.elr', 'thumb_path' => 'screens/none/'.$i.'-thumb.elr', 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('screens')->insert($chunk);
    }
}

/** Another workspace's device, its recording with a screen, and its project. */
function strangerWithScreen(object $test): array
{
    [$device, $token] = $test->enroll();
    $session = $test->makeReadySession($device, Fixtures::audio(1000), ['status' => SessionStatus::Completed]);
    $screen = keepScreen($session, screenPicture(seed: 99));
    $project = Client::factory()->create(['user_id' => $device->user_id]);
    $test->asToken($test->token);

    return ['session' => $session, 'screen' => $screen, 'project' => $project];
}

describe('sending a screen', function () {
    it('keeps a picture at its moment and answers 201 with the documented screen', function () {
        $webp = screenPicture();

        $response = sendScreen($this, $this->session->id, $webp)->assertCreated();

        expect(array_keys($response->json('data')))->toBe(['id', 'index', 'at_ms', 'width', 'height', 'trigger', 'ocr_text', 'description', 'image_url', 'thumb_url', 'created_at'])
            ->and($response->json('data'))->toMatchArray(['index' => 1, 'at_ms' => 872000, 'width' => 1920, 'height' => 1080, 'trigger' => 'change', 'ocr_text' => null, 'description' => null]);
        parse_str((string) parse_url((string) $response->json('data.image_url'), PHP_URL_QUERY), $query);
        expect($query)->toHaveKeys(['expires', 'signature'])
            ->and((int) $query['expires'])->toBeGreaterThanOrEqual(now()->addMinutes(30)->getTimestamp() - 2)->toBeLessThanOrEqual(now()->addMinutes(30)->getTimestamp())
            ->and(Storage::disk('recordings')->allFiles('screens'))->toHaveCount(2);
    });

    it('answers 200 with the screen already kept when the same picture comes again', function () {
        $webp = screenPicture();
        $first = sendScreen($this, $this->session->id, $webp)->assertCreated()->json('data.id');

        $again = sendScreen($this, $this->session->id, $webp, ['at_ms' => 5000, 'trigger' => 'manual'])->assertOk();

        expect($again->json('data.id'))->toBe($first)->and($again->json('data.at_ms'))->toBe(872000)
            ->and(unscoped(fn () => Screen::query()->count()))->toBe(1)
            ->and(Storage::disk('recordings')->allFiles('screens'))->toHaveCount(2);
    });

    it('reads the type from the bytes: a PNG named .webp and a JPEG are kept as what they are', function () {
        $png = screenPicture('png', seed: 2);
        $jpeg = screenPicture('jpeg', seed: 3);

        $one = sendScreen($this, $this->session->id, $png, [], 'screen.webp')->assertCreated()->json('data.id');
        $two = sendScreen($this, $this->session->id, $jpeg, ['at_ms' => 1000], 'screen.png')->assertCreated()->json('data.id');

        expect(unscoped(fn () => Screen::query()->find($one))->mime)->toBe('image/png')
            ->and(unscoped(fn () => Screen::query()->find($two))->mime)->toBe('image/jpeg');
    });

    it('refuses what is not a WebP, PNG or JPEG picture, whatever it is named', function (string $bytes) {
        sendScreen($this, $this->session->id, $bytes, [], 'screen.webp')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.image.0', 'Send a WebP, PNG or JPEG picture.');

        expect(unscoped(fn () => Screen::query()->count()))->toBe(0)->and(Storage::disk('recordings')->allFiles('screens'))->toBe([]);
    })->with([
        'a PDF' => ["%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF"],
        'an SVG with a script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'a GIF' => [base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')],
        'a PNG header with nothing after it' => ["\x89PNG\r\n\x1a\n".str_repeat("\0", 40)],
        'a WebP header over a PNG' => ['RIFF'.pack('V', 100).'WEBP'.substr(screenPicture('png'), 0, 200)],
    ]);

    it('refuses a picture over 3 MB with 413 too_large', function () {
        $big = substr(screenPicture('png'), 0, 64).str_repeat('x', ScreenStore::MAX_BYTES);

        sendScreen($this, $this->session->id, $big)->assertStatus(413)->assertJsonPath('code', 'too_large');
    });

    it('refuses a SHA-256 that is not the picture\'s', function () {
        sendScreen($this, $this->session->id, screenPicture(), ['sha256' => hash('sha256', 'other')])
            ->assertStatus(422)->assertJsonPath('errors.sha256.0', 'The SHA-256 does not match the picture.');
    });

    it('takes moments from 0 to 60 seconds past the end of the recording', function () {
        sendScreen($this, $this->session->id, screenPicture(seed: 4), ['at_ms' => 0])->assertCreated();
        sendScreen($this, $this->session->id, screenPicture(seed: 5), ['at_ms' => 960_000])->assertCreated();

        sendScreen($this, $this->session->id, screenPicture(seed: 6), ['at_ms' => 960_001])->assertStatus(422)->assertJsonValidationErrors('at_ms');
        sendScreen($this, $this->session->id, screenPicture(seed: 6), ['at_ms' => -1])->assertStatus(422)->assertJsonValidationErrors('at_ms');
    });

    it('measures a recording still being recorded by the time since it started', function () {
        $live = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Recording,
            'started_at' => now()->subMinutes(5), 'ended_at' => null, 'duration_ms' => null]);

        sendScreen($this, $live->id, screenPicture(seed: 7), ['at_ms' => 290_000])->assertCreated();
        sendScreen($this, $live->id, screenPicture(seed: 8), ['at_ms' => 400_000])->assertStatus(422)->assertJsonValidationErrors('at_ms');
    });

    it('validates the size, the trigger and the fields', function (array $fields, string $field) {
        sendScreen($this, $this->session->id, screenPicture(), $fields)->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        'no width' => [['width' => null], 'width'],
        'zero height' => [['height' => 0], 'height'],
        'an unknown trigger' => [['trigger' => 'timer'], 'trigger'],
        'a short sha256' => [['sha256' => 'abc'], 'sha256'],
        'no moment' => [['at_ms' => null], 'at_ms'],
    ]);

    it('keeps at most 400 screens per recording; the same picture again still answers 200', function () {
        $kept = screenPicture(seed: 10);
        sendScreen($this, $this->session->id, $kept)->assertCreated();
        screenRows($this->session, ScreenStore::MAX_PER_RECORDING - 1, 10_000);

        sendScreen($this, $this->session->id, screenPicture(seed: 11))->assertStatus(409)
            ->assertExactJson(['message' => 'This recording already keeps 400 screens.', 'code' => 'too_many_screens']);
        sendScreen($this, $this->session->id, $kept)->assertOk();
        expect(unscoped(fn () => Screen::query()->count()))->toBe(400);
    });

    it('answers 404 session_not_found for a recording in the undo bin, and keeps nothing', function () {
        $this->deleteJson("/api/sessions/{$this->session->id}")->assertNoContent();

        sendScreen($this, $this->session->id, screenPicture())->assertNotFound()->assertJsonPath('code', 'session_not_found');
        $this->getJson("/api/sessions/{$this->session->id}/screens")->assertNotFound()->assertJsonPath('code', 'session_not_found');
        expect(unscoped(fn () => Screen::query()->count()))->toBe(0);
    });

    it('needs sessions:write and counts against the upload limit, not the general one', function () {
        [, $readOnly] = $this->enroll($this->device->user, ['library:read']);
        $this->asToken($readOnly);

        sendScreen($this, $this->session->id, screenPicture())->assertForbidden()->assertJsonPath('code', 'missing_ability');
        $route = Route::getRoutes()->getByName('api.sessions.screens.store');
        expect($route->gatherMiddleware())->toContain('throttle:uploads', 'abilities:sessions:write')->not->toContain('throttle:device');
    });

    it('makes the library sync the recording again with its new screen_count', function () {
        $this->travel(5)->seconds();
        $since = now()->toAtomString();
        sendScreen($this, $this->session->id, screenPicture())->assertCreated();

        $item = collect($this->getJson('/api/library?since='.urlencode($since))->assertOk()->json('data'))->firstWhere('id', $this->session->id);
        expect($item['screen_count'])->toBe(1)
            ->and($this->getJson("/api/sessions/{$this->session->id}")->assertOk()->json('screen_count'))->toBe(1);
    });
});

describe('listing and deleting', function () {
    it('lists the screens in time order, numbered from 1', function () {
        $late = sendScreen($this, $this->session->id, screenPicture(seed: 1), ['at_ms' => 600_000])->json('data.id');
        $early = sendScreen($this, $this->session->id, screenPicture(seed: 2), ['at_ms' => 30_000])->assertJsonPath('data.index', 1)->json('data.id');
        $middle = sendScreen($this, $this->session->id, screenPicture(seed: 3), ['at_ms' => 120_000, 'trigger' => 'words'])->assertJsonPath('data.index', 2)->json('data.id');

        $data = $this->getJson("/api/sessions/{$this->session->id}/screens")->assertOk()->json('data');

        expect(array_column($data, 'id'))->toBe([$early, $middle, $late])
            ->and(array_column($data, 'index'))->toBe([1, 2, 3])
            ->and($data[1]['trigger'])->toBe('words');
    });

    it('deletes a screen with its files; the others are numbered again when the list is read', function () {
        $first = sendScreen($this, $this->session->id, screenPicture(seed: 1), ['at_ms' => 1000])->json('data.id');
        sendScreen($this, $this->session->id, screenPicture(seed: 2), ['at_ms' => 2000]);

        $this->deleteJson("/api/sessions/{$this->session->id}/screens/{$first}")->assertNoContent();

        expect(Storage::disk('recordings')->allFiles('screens'))->toHaveCount(2)
            ->and($this->getJson("/api/sessions/{$this->session->id}/screens")->json('data.0.index'))->toBe(1);
        $this->deleteJson("/api/sessions/{$this->session->id}/screens/{$first}")->assertNotFound()->assertJsonPath('code', 'not_found');
    });

    it('never shows, deletes or adds to another workspace\'s recordings, screens or projects', function () {
        $theirs = strangerWithScreen($this);

        $this->getJson("/api/sessions/{$theirs['session']->id}/screens")->assertNotFound()->assertJsonPath('code', 'session_not_found');
        sendScreen($this, $theirs['session']->id, screenPicture())->assertNotFound()->assertJsonPath('code', 'session_not_found');
        $this->deleteJson("/api/sessions/{$theirs['session']->id}/screens/{$theirs['screen']->id}")->assertNotFound()->assertJsonPath('code', 'session_not_found');
        // Their screen's id with my recording is as unknown as any other.
        $this->deleteJson("/api/sessions/{$this->session->id}/screens/{$theirs['screen']->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson("/api/clients/{$theirs['project']->id}/screens")->assertNotFound()->assertJsonPath('code', 'client_not_found');
        expect(unscoped(fn () => Screen::query()->whereKey($theirs['screen']->id)->exists()))->toBeTrue();
    });
});

describe('the signed picture links', function () {
    beforeEach(function () {
        $this->webp = screenPicture('png', 1280, 720);
        $this->screen = sendScreen($this, $this->session->id, $this->webp)->assertCreated()->json('data');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    });

    it('serve the decrypted picture and its WebP thumbnail with no token, kept privately', function () {
        $image = $this->get($this->screen['image_url'])->assertOk();
        $thumb = $this->get($this->screen['thumb_url'])->assertOk();

        expect($image->getContent())->toBe($this->webp)
            ->and($image->headers->get('Content-Type'))->toBe('image/png')
            ->and($image->headers->get('Cache-Control'))->toContain('private')->not->toContain('public')
            ->and($thumb->headers->get('Content-Type'))->toBe('image/webp')
            ->and(getimagesizefromstring((string) $thumb->getContent())[0])->toBe(480);
    });

    it('refuse a changed signature, another screen or picture in the same link, and an expired link', function () {
        $this->get(str_replace('signature=', 'signature=0', $this->screen['image_url']))->assertForbidden();
        $this->get(str_replace('/image?', '/thumb?', $this->screen['image_url']))->assertForbidden();
        $other = (string) Str::ulid();
        $this->get(str_replace($this->screen['id'], $other, $this->screen['image_url']))->assertForbidden();
        $this->get(strtok($this->screen['image_url'], '?'))->assertForbidden();

        $this->travel(31)->minutes();
        $this->get($this->screen['image_url'])->assertForbidden();
    });

    it('stop working while the recording is in the undo bin', function () {
        unscoped(fn () => CaptureSession::query()->find($this->session->id)?->delete());

        $this->get($this->screen['image_url'])->assertNotFound();
        $this->get($this->screen['thumb_url'])->assertNotFound();
    });
});

describe('a recording a teammate sent', function () {
    beforeEach(function () {
        sendScreen($this, $this->session->id, screenPicture(), ['at_ms' => 4000])->assertCreated();
        $this->omar = User::factory()->create(['email' => 'omar@example.com']);
        [, $this->omarToken] = $this->enroll($this->omar);
        $this->postJson("/api/sessions/{$this->session->id}/send", ['to' => ['omar@example.com']])->assertCreated();
        $this->asToken($this->omarToken);
    });

    it('shows its screens and their pictures, read-only', function () {
        $data = $this->getJson("/api/sessions/{$this->session->id}/screens")->assertOk()->json('data');

        expect($data)->toHaveCount(1)->and($data[0]['index'])->toBe(1)
            ->and($this->getJson("/api/sessions/{$this->session->id}")->assertOk()->json('screen_count'))->toBe(1)
            ->and(collect($this->getJson('/api/library?folder=shared')->assertOk()->json('data'))->firstWhere('id', $this->session->id)['screen_count'])->toBe(1);
        $this->flushHeaders();
        $this->get($data[0]['thumb_url'])->assertOk();
    });

    it('cannot add or delete its screens', function () {
        $screen = $this->getJson("/api/sessions/{$this->session->id}/screens")->json('data.0.id');

        sendScreen($this, $this->session->id, screenPicture(seed: 5))->assertNotFound();
        $this->deleteJson("/api/sessions/{$this->session->id}/screens/{$screen}")->assertNotFound();
        expect(unscoped(fn () => Screen::query()->count()))->toBe(1);
    });
});

describe('a project\'s screens', function () {
    beforeEach(function () {
        $this->project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $this->subfolder = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Website', 'parent_id' => $this->project->id]);
        $session = fn (array $attributes): CaptureSession => CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, ...$attributes]);
        $this->newer = $session(['client_id' => $this->project->id, 'started_at' => now()->subDay(), 'title' => 'Kickoff']);
        $this->older = $session(['client_id' => $this->project->id, 'started_at' => now()->subDays(3), 'title' => 'Review']);
        $this->nested = $session(['client_id' => $this->subfolder->id, 'started_at' => now()->subDays(2)]);
        $this->binned = $session(['client_id' => $this->project->id, 'started_at' => now()]);
        $this->recording = $session(['client_id' => $this->project->id, 'started_at' => now(), 'status' => SessionStatus::Recording, 'duration_ms' => null]);
        screenRows($this->newer, 50);
        screenRows($this->older, 15);
        screenRows($this->nested, 2);
        screenRows($this->binned, 1);
        screenRows($this->recording, 1);
        $this->binned->delete();
    });

    it('lists the newest recording first and each one\'s screens in time order, 60 a page', function () {
        $first = $this->getJson("/api/clients/{$this->project->id}/screens")->assertOk();
        $page = $first->json('data');

        expect($page)->toHaveCount(60)
            ->and(array_unique(array_column(array_slice($page, 0, 50), 'session_id')))->toBe([$this->newer->id])
            ->and(array_column(array_slice($page, 0, 50), 'index'))->toBe(range(1, 50))
            ->and(array_column(array_slice($page, 50), 'index'))->toBe(range(1, 10))
            ->and($page[50])->toMatchArray(['session_id' => $this->older->id, 'session_title' => 'Review', 'session_started_at' => $this->older->started_at->toAtomString()])
            ->and(array_keys($page[0]))->toContain('image_url', 'thumb_url', 'session_id', 'session_title', 'session_started_at')
            ->and($first->json('next_cursor'))->toBeString();

        $second = $this->getJson("/api/clients/{$this->project->id}/screens?cursor=".$first->json('next_cursor'))->assertOk();
        expect(array_column($second->json('data'), 'index'))->toBe(range(11, 15))
            ->and($second->json('next_cursor'))->toBeNull();
    });

    it('adds the subfolders\' recordings with include_subfolders=1, in start order with the others', function () {
        $all = [];
        $cursor = null;
        do {
            $answer = $this->getJson("/api/clients/{$this->project->id}/screens?include_subfolders=1".($cursor !== null ? '&cursor='.$cursor : ''))->assertOk();
            array_push($all, ...$answer->json('data'));
            $cursor = $answer->json('next_cursor');
        } while ($cursor !== null);

        expect($all)->toHaveCount(67)
            ->and(array_values(array_unique(array_column($all, 'session_id'))))->toBe([$this->newer->id, $this->nested->id, $this->older->id]);
    });

    it('refuses a cursor it did not issue with 422 invalid_cursor', function () {
        $this->getJson("/api/clients/{$this->project->id}/screens?cursor=not-a-cursor")->assertStatus(422)->assertJsonPath('code', 'invalid_cursor');
        $forged = rtrim(strtr(base64_encode((string) json_encode(['s' => 'yesterday', 'c' => 'x', 'a' => 1, 'i' => 'y'])), '+/', '-_'), '=');
        $this->getJson("/api/clients/{$this->project->id}/screens?cursor={$forged}")->assertStatus(422)->assertJsonPath('code', 'invalid_cursor');
    });
});

describe('projects', function () {
    it('capture screens by default and can be told not to', function () {
        $project = Client::factory()->create(['user_id' => $this->device->user_id]);

        expect(collect($this->getJson('/api/clients')->assertOk()->json('data'))->firstWhere('id', $project->id)['screens'])->toBeTrue();
        $this->patchJson("/api/clients/{$project->id}", ['screens' => false])->assertOk()->assertJsonPath('data.screens', false);
        expect($project->fresh()->screens)->toBeFalse();
        $this->postJson('/api/clients', ['name' => 'New one'])->assertCreated()->assertJsonPath('data.screens', true);
        $this->patchJson("/api/clients/{$project->id}", ['screens' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('screens');
    });
});

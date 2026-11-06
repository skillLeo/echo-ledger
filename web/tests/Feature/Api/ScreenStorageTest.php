<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Screen;
use App\Models\User;
use App\Services\RecordingCipher;
use App\Services\RetentionPolicy;
use App\Services\Screens\ScreenStore;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/Support/screen_helpers.php';

/*
 * Screen moments on disk (v1.5, docs/API.md "Screen moments"): encrypted like recordings, a 480 px WebP thumbnail made
 * at upload, erased with the recording (retention, the undo bin, an erase now) and copied with "Save to my library".
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->session = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => SessionStatus::Completed]);
});

/** @return list<string> every screen file on the recordings disk */
function screenFiles(): array
{
    return Storage::disk('recordings')->allFiles('screens');
}

it('keeps each picture and a 480 px WebP thumbnail encrypted at rest, each bound to its screen', function () {
    $png = screenPicture('png', 1920, 1080);
    $screen = keepScreen($this->session, $png);

    expect(screenFiles())->toHaveCount(2)
        ->and($screen->image_path)->toStartWith('screens/'.now()->format('Y/m').'/')
        ->and($screen->mime)->toBe('image/png')
        ->and($screen->bytes)->toBe(strlen($png))
        ->and($screen->workspace_id)->toBe($this->session->workspace_id);
    foreach (screenFiles() as $file) {
        $raw = (string) Storage::disk('recordings')->get($file);
        expect($raw)->toStartWith(RecordingCipher::MAGIC)->not->toContain("\x89PNG")->not->toContain('WEBPVP8');
    }
    $store = app(ScreenStore::class);
    expect($store->picture($screen))->toBe($png);
    $thumb = (string) $store->picture($screen, thumb: true);
    $size = getimagesizefromstring($thumb);
    expect($size[0])->toBe(480)->and($size[1])->toBe(270)->and($size['mime'])->toBe('image/webp');
    // The thumbnail's file cannot pass for the picture (nor another screen's file for this one).
    expect(fn () => app(RecordingCipher::class)->decryptToMemory(Storage::disk('recordings')->path((string) $screen->thumb_path), $screen->id))
        ->toThrow(RuntimeException::class);
});

it('keeps a picture narrower than 480 px at its own width in the thumbnail', function () {
    $screen = keepScreen($this->session, screenPicture('jpeg', 320, 200));

    $size = getimagesizefromstring((string) app(ScreenStore::class)->picture($screen, thumb: true));
    expect($size[0])->toBe(320)->and($size[1])->toBe(200)->and($size['mime'])->toBe('image/webp');
});

it('erases the screens at once with a recording deleted while it is still recording', function () {
    $live = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Recording]);
    keepScreen($live, screenPicture(seed: 1));
    keepScreen($live, screenPicture(seed: 2), ['at_ms' => 5000]);
    expect(screenFiles())->toHaveCount(4);

    $this->deleteJson("/api/sessions/{$live->id}")->assertNoContent();

    expect(screenFiles())->toBe([])->and(unscoped(fn () => Screen::query()->count()))->toBe(0);
});

it('keeps the screens in the undo bin, brings them back with a restore and erases them when the bin ends', function () {
    keepScreen($this->session, screenPicture());

    $this->deleteJson("/api/sessions/{$this->session->id}")->assertNoContent();
    expect(screenFiles())->toHaveCount(2);
    $this->postJson("/api/sessions/{$this->session->id}/restore")->assertOk();
    expect(screenFiles())->toHaveCount(2)->and(unscoped(fn () => Screen::query()->count()))->toBe(1);

    $this->deleteJson("/api/sessions/{$this->session->id}")->assertNoContent();
    emptyUndoBin();

    expect(screenFiles())->toBe([])->and(unscoped(fn () => Screen::query()->count()))->toBe(0);
});

it('erases them when retention moves the recording to the bin and the bin ends, and with Erase now', function () {
    app(SettingsRepository::class)->set('retention_days', 7);
    $old = $this->makeReadySession($this->device, Fixtures::audio(2000), ['status' => SessionStatus::Completed]);
    unscoped(fn () => CaptureSession::query()->whereKey($old->id)->update(['created_at' => now()->subDays(10)]));
    keepScreen($old, screenPicture(seed: 3));
    $erasedNow = keepScreen($this->session, screenPicture(seed: 4));

    expect(app(RetentionPolicy::class)->applyRetention())->toBe(1);
    emptyUndoBin();
    expect(unscoped(fn () => Screen::query()->pluck('id')->all()))->toBe([$erasedNow->id]);

    $this->deleteJson("/api/sessions/{$this->session->id}")->assertNoContent();
    $this->deleteJson("/api/bin/{$this->session->id}")->assertNoContent();
    expect(screenFiles())->toBe([])->and(unscoped(fn () => Screen::query()->count()))->toBe(0);
});

it('copies the screens with "Save to my library", encrypted again and independent of the original', function () {
    $this->session->update(['title' => 'Call with Omar']);
    $this->session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'en',
        'text' => 'Look at this page', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Look at this page']]]);
    $first = keepScreen($this->session, screenPicture('png', seed: 5), ['at_ms' => 2000]);
    $first->forceFill(['ocr_text' => 'Invoice #2041', 'description' => 'The invoice page', 'described_at' => now()])->save();
    $second = keepScreen($this->session, screenPicture(seed: 6), ['at_ms' => 9000, 'trigger' => 'words']);
    $omar = User::factory()->create(['email' => 'omar@example.com']);
    [, $omarToken] = $this->enroll($omar);
    $this->postJson("/api/sessions/{$this->session->id}/send", ['to' => ['omar@example.com']])->assertCreated();
    $item = $this->asToken($omarToken)->getJson('/api/shared-with-me')->assertOk()->json('data.0.id');

    $copyId = $this->postJson("/api/shared-with-me/{$item}/save")->assertCreated()->json('session_id');

    $copies = unscoped(fn () => ScreenStore::ordered($copyId)->get());
    expect($copies)->toHaveCount(2)
        ->and($copies->pluck('id')->intersect([$first->id, $second->id])->all())->toBe([])
        ->and($copies->pluck('workspace_id')->unique()->all())->toBe([$omar->fresh()->current_workspace_id])
        ->and($copies[0]->only(['at_ms', 'trigger', 'mime', 'sha256', 'ocr_text', 'description']))->toBe($first->only(['at_ms', 'trigger', 'mime', 'sha256', 'ocr_text', 'description']))
        ->and($copies[1]->trigger)->toBe('words')
        ->and($copies[0]->image_path)->not->toBe($first->image_path)
        ->and(screenFiles())->toHaveCount(8);

    // The sender erases the original: the copy's pictures stay and still open.
    $this->asToken($this->token)->deleteJson("/api/sessions/{$this->session->id}")->assertNoContent();
    emptyUndoBin();
    $store = app(ScreenStore::class);
    expect(screenFiles())->toHaveCount(4)
        ->and($store->picture($copies[0]))->toBe(screenPicture('png', seed: 5))
        ->and(getimagesizefromstring((string) $store->picture($copies[1], thumb: true))[0])->toBe(480);
});

it('leaves no file of a screen behind when "Save to my library" fails while copying it', function () {
    $screen = keepScreen($this->session, screenPicture('png', seed: 7));
    // The thumbnail no longer opens, so the copy fails after the picture's copy was written.
    $thumb = Storage::disk('recordings')->path((string) $screen->thumb_path);
    $raw = (string) file_get_contents($thumb);
    file_put_contents($thumb, substr($raw, 0, -1).chr(ord($raw[strlen($raw) - 1]) ^ 1));
    $omar = User::factory()->create(['email' => 'omar@example.com']);
    [, $omarToken] = $this->enroll($omar);
    $this->postJson("/api/sessions/{$this->session->id}/send", ['to' => ['omar@example.com']])->assertCreated();
    $item = $this->asToken($omarToken)->getJson('/api/shared-with-me')->assertOk()->json('data.0.id');
    $before = Storage::disk('recordings')->allFiles();

    $this->postJson("/api/shared-with-me/{$item}/save")->assertServerError();

    expect(Storage::disk('recordings')->allFiles())->toBe($before)
        ->and(unscoped(fn () => Screen::query()->count()))->toBe(1)
        ->and(unscoped(fn () => CaptureSession::query()->where('workspace_id', $omar->fresh()->current_workspace_id)->count()))->toBe(0);
});

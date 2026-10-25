<?php

declare(strict_types=1);

use App\Enums\UploadStatus;
use App\Models\CaptureSession;
use App\Models\Recording;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->token = $token;
    $this->asToken($token);
    $this->bytes = Fixtures::audio(4096, 'AUDIO-MARK');
    $this->flow = $this->uploadRecording($this->bytes);
    $this->url = $this->getJson("/api/sessions/{$this->flow['session']}")->assertOk()->json('playback_url');
    // Playback URLs are capabilities: the player never sends the bearer token with them.
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
});

describe('the signed URL', function () {
    it('is only handed out once the recording is ready', function () {
        $pending = $this->asToken($this->token)->createSession(Fixtures::audio(2000), ['client_ref' => 'pending'])->assertCreated();

        $this->asToken($this->token)->getJson('/api/sessions/'.$pending->json('session.id'))->assertOk()->assertJsonPath('playback_url', null);
    });

    it('is a temporary signed route on the API host that expires in the configured time', function () {
        $this->freezeTime();
        $url = $this->asToken($this->token)->getJson("/api/sessions/{$this->flow['session']}")->json('playback_url');
        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        expect($parts['path'])->toBe("/api/recordings/{$this->flow['recording']}/audio")
            ->and($query)->toHaveKeys(['expires', 'signature'])
            ->and((int) $query['expires'])->toBe(now()->addMinutes(30)->getTimestamp());
    });

    it('streams the decrypted recording without any bearer token', function () {
        $response = $this->get($this->url);

        $response->assertOk();
        expect($response->streamedContent())->toBe($this->bytes);
    });

    it('works for the whole thirty minutes and not after', function () {
        $this->freezeTime();
        $url = $this->asToken($this->token)->getJson("/api/sessions/{$this->flow['session']}")->json('playback_url');

        $this->travel(29)->minutes();
        $this->get($url)->assertOk();

        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    });

    it('honours a changed validity period', function () {
        config(['echoledger.playback_url_minutes' => 5]);
        $this->freezeTime();
        $url = $this->asToken($this->token)->getJson("/api/sessions/{$this->flow['session']}")->json('playback_url');

        $this->travel(4)->minutes();
        $this->get($url)->assertOk();
        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    });

    it('rejects a missing, altered or reused-for-another-recording signature', function () {
        $id = $this->flow['recording'];
        $other = $this->asToken($this->token)->uploadRecording(Fixtures::audio(3000, 'OTHER'), ['client_ref' => 'other'])['recording'];
        $this->flushHeaders();
        parse_str(parse_url($this->url, PHP_URL_QUERY), $query);

        $this->get("/api/recordings/{$id}/audio")->assertForbidden();
        $this->get("/api/recordings/{$id}/audio?expires={$query['expires']}")->assertForbidden();
        $this->get("/api/recordings/{$id}/audio?expires={$query['expires']}&signature=".strrev($query['signature']))->assertForbidden();
        $this->get("/api/recordings/{$other}/audio?expires={$query['expires']}&signature={$query['signature']}")->assertForbidden();
        $this->get("/api/recordings/{$id}/audio?expires=".($query['expires'] + 3600)."&signature={$query['signature']}")->assertForbidden();
    });

    it('rejects extra query parameters appended to a valid URL', function () {
        $this->get($this->url.'&download=1')->assertForbidden();
    });

    it('rejects the URL when the scheme is downgraded', function () {
        $this->get(str_replace('https://', 'http://', $this->url))->assertForbidden();
    });

    it('does not accept a bearer token as a substitute for the signature', function () {
        $this->asToken($this->token)->getJson("/api/recordings/{$this->flow['recording']}/audio")->assertForbidden();
    });

    it('does not exist for recordings that are not ready', function (UploadStatus $status) {
        Recording::query()->whereKey($this->flow['recording'])->update(['upload_status' => $status]);

        $this->get($this->url)->assertNotFound();
    })->with([UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Assembling, UploadStatus::Failed]);

    it('does not exist once the session has been deleted', function () {
        $this->asToken($this->token)->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();
        $this->flushHeaders();

        $this->get($this->url)->assertNotFound();
    });

    it('does not exist for an unknown recording even with a valid signature', function () {
        $url = URL::temporarySignedRoute('api.recordings.audio', now()->addMinutes(5), ['recording' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

        $this->get($url)->assertNotFound();
    });

    it('only answers GET', function () {
        $this->post($this->url)->assertStatus(405);
    });
});

describe('response headers', function () {
    it('declares the stored MIME type', function (string $mime) {
        $flow = $this->asToken($this->token)->uploadRecording(Fixtures::audio(2500), ['client_ref' => 'mime-'.$mime], ['mime' => $mime]);
        $url = $this->getJson("/api/sessions/{$flow['session']}")->json('playback_url');
        $this->flushHeaders();

        $response = $this->get($url)->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith($mime);
    })->with(['audio/ogg', 'audio/flac', 'audio/wav', 'audio/mpeg', 'audio/mp4', 'audio/webm', 'audio/aac']);

    it('forbids caching and sniffing', function () {
        $response = $this->get($this->url)->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('advertises range support and the exact length', function () {
        $response = $this->get($this->url)->assertOk();

        expect($response->headers->get('Accept-Ranges'))->toBe('bytes')
            ->and((int) $response->headers->get('Content-Length'))->toBe(strlen($this->bytes));
    });

    it('does not set cookies', function () {
        $response = $this->get($this->url)->assertOk();

        expect($response->headers->getCookies())->toBe([]);
    });

    it('does not leak the stored file name or storage path in headers', function () {
        $response = $this->get($this->url)->assertOk();
        $stored = (string) Recording::query()->find($this->flow['recording'])->stored_path;

        expect(json_encode($response->headers->all()))->not->toContain(basename($stored))->not->toContain('.elr');
        expect((string) $response->headers->get('Content-Disposition'))->not->toContain('.elr');
    });
});

describe('byte ranges', function () {
    it('serves a bounded range as 206 with the matching bytes', function () {
        $response = $this->withHeaders(['Range' => 'bytes=0-99'])->get($this->url);

        $response->assertStatus(206);
        expect($response->headers->get('Content-Range'))->toBe('bytes 0-99/4096')
            ->and((int) $response->headers->get('Content-Length'))->toBe(100)
            ->and($response->streamedContent())->toBe(substr($this->bytes, 0, 100));
    });

    it('serves a range from the middle', function () {
        $response = $this->withHeaders(['Range' => 'bytes=1000-1999'])->get($this->url);

        $response->assertStatus(206);
        expect($response->headers->get('Content-Range'))->toBe('bytes 1000-1999/4096')
            ->and($response->streamedContent())->toBe(substr($this->bytes, 1000, 1000));
    });

    it('serves an open-ended range to the end', function () {
        $response = $this->withHeaders(['Range' => 'bytes=4000-'])->get($this->url);

        $response->assertStatus(206);
        expect($response->headers->get('Content-Range'))->toBe('bytes 4000-4095/4096')
            ->and($response->streamedContent())->toBe(substr($this->bytes, 4000));
    });

    it('serves a suffix range as the last bytes', function () {
        $response = $this->withHeaders(['Range' => 'bytes=-96'])->get($this->url);

        $response->assertStatus(206);
        expect($response->headers->get('Content-Range'))->toBe('bytes 4000-4095/4096')
            ->and($response->streamedContent())->toBe(substr($this->bytes, -96));
    });

    it('serves a single byte', function () {
        $response = $this->withHeaders(['Range' => 'bytes=7-7'])->get($this->url);

        $response->assertStatus(206);
        expect($response->streamedContent())->toBe($this->bytes[7])->and($response->headers->get('Content-Range'))->toBe('bytes 7-7/4096');
    });

    it('serves the final byte', function () {
        $response = $this->withHeaders(['Range' => 'bytes=4095-4095'])->get($this->url);

        $response->assertStatus(206);
        expect($response->streamedContent())->toBe($this->bytes[4095]);
    });

    it('clamps an end beyond the file to the last byte', function () {
        $response = $this->withHeaders(['Range' => 'bytes=4000-999999'])->get($this->url);

        $response->assertStatus(206);
        expect($response->headers->get('Content-Range'))->toBe('bytes 4000-4095/4096')
            ->and($response->streamedContent())->toBe(substr($this->bytes, 4000));
    });

    it('answers a range covering the whole file with the whole file', function () {
        $response = $this->withHeaders(['Range' => 'bytes=0-4095'])->get($this->url);

        expect($response->getStatusCode())->toBeIn([200, 206]);
        expect($response->streamedContent())->toBe($this->bytes);
    });

    it('answers 416 with the size when the start is past the end', function () {
        $response = $this->withHeaders(['Range' => 'bytes=5000-6000'])->get($this->url);

        $response->assertStatus(416);
        expect($response->headers->get('Content-Range'))->toBe('bytes */4096');
    });

    it('answers 416 when the start equals the file size', function () {
        $response = $this->withHeaders(['Range' => 'bytes=4096-'])->get($this->url);

        $response->assertStatus(416);
        expect($response->headers->get('Content-Range'))->toBe('bytes */4096');
    });

    it('never answers a range request with more bytes than were asked for', function (string $range, int $max) {
        $response = $this->withHeaders(['Range' => $range])->get($this->url);

        expect(strlen($response->streamedContent()))->toBeLessThanOrEqual($max);
    })->with([
        ['bytes=0-0', 1],
        ['bytes=10-19', 10],
        ['bytes=-1', 1],
    ]);

    it('ignores a range in a unit it does not understand', function () {
        $response = $this->withHeaders(['Range' => 'items=0-10'])->get($this->url);

        $response->assertOk();
        expect($response->streamedContent())->toBe($this->bytes);
    });

    it('does not fail on a garbled Range header', function (string $range) {
        $response = $this->withHeaders(['Range' => $range])->get($this->url);

        expect($response->getStatusCode())->toBeLessThan(500);
    })->with(['bytes=', 'bytes=abc-def', 'bytes=--5', 'bytes=1-2-3', 'bytes=,', 'bytes=0-1,5-6']);

    it('treats a range whose end precedes its start as invalid rather than returning data', function () {
        $response = $this->withHeaders(['Range' => 'bytes=500-100'])->get($this->url);

        // RFC 9110: such a spec is invalid and the header is ignored (200), or the request is unsatisfiable (416).
        expect($response->getStatusCode())->toBeIn([200, 416]);
        if ($response->getStatusCode() === 200) {
            expect($response->streamedContent())->toBe($this->bytes);
        }
    });

    it('reassembles the original file from consecutive ranges', function () {
        $assembled = '';
        for ($start = 0; $start < 4096; $start += 1000) {
            $end = min($start + 999, 4095);
            $assembled .= $this->withHeaders(['Range' => "bytes={$start}-{$end}"])->get($this->url)->streamedContent();
        }

        expect($assembled)->toBe($this->bytes);
    });
});

describe('large recordings', function () {
    it('serves a range from the middle of a multi-megabyte recording', function () {
        $bytes = Fixtures::audio(3_000_000, 'BIG');
        $flow = $this->asToken($this->token)->uploadRecording($bytes, ['client_ref' => 'big']);
        $url = $this->getJson("/api/sessions/{$flow['session']}")->json('playback_url');
        $this->flushHeaders();

        $response = $this->withHeaders(['Range' => 'bytes=1500000-1500999'])->get($url);

        $response->assertStatus(206);
        expect($response->streamedContent())->toBe(substr($bytes, 1_500_000, 1000))
            ->and($response->headers->get('Content-Range'))->toBe('bytes 1500000-1500999/3000000');
    });
});

describe('who can obtain a URL', function () {
    it('is not obtainable by a device of another owner', function () {
        [, $stranger] = $this->enroll();

        $this->asToken($stranger)->getJson("/api/sessions/{$this->flow['session']}")->assertNotFound();
    });

    it('is not obtainable for a deleted session', function () {
        CaptureSession::query()->whereKey($this->flow['session'])->first()->delete();

        $this->asToken($this->token)->getJson("/api/sessions/{$this->flow['session']}")->assertNotFound();
    });

    it('is gone from the disk when the recording row goes', function () {
        $path = (string) Recording::query()->find($this->flow['recording'])->stored_path;
        expect(Storage::disk('recordings')->exists($path))->toBeTrue();

        $this->asToken($this->token)->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();

        expect(Storage::disk('recordings')->exists($path))->toBeFalse();
    });
});

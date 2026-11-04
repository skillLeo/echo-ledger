<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ShareLink;
use App\Models\ShareLinkView;
use App\Models\User;
use App\Services\ShareLinks;
use App\Services\Views\TranscriptViews;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Share links (since v1.5)": audiences (public, password, people), allow_download with the ZIP, expires_at,
 * project links (/p/) and GET /api/shares, next to the v1.1 body that still works as before.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    $this->audio = Fixtures::audio(4000);
    $this->session = sharedRecording($this, 'Budget review', $this->audio);
});

function sharedRecording(object $test, string $title, string $audio, ?Client $client = null, array $state = []): CaptureSession
{
    $session = $test->makeReadySession($test->device, $audio, ['title' => $title, 'status' => SessionStatus::Completed, 'client_id' => $client?->id, ...$state]);
    $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'en', 'text' => 'x',
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => "Secret words of {$title}"]]]);

    return $session;
}

function shareOf(object $test, array $body, ?string $path = null): array
{
    return $test->asToken($test->token)->postJson($path ?? "/api/sessions/{$test->session->id}/shares", $body)->assertCreated()->json();
}

/** A request from a browser without the device token. */
function browser(object $test): object
{
    app('auth')->forgetGuards();

    return $test->withHeaders(['Authorization' => '']);
}

function pathOf(array $share): string
{
    return (string) parse_url($share['url'], PHP_URL_PATH);
}

/** @return array<string, string> the files in a downloaded ZIP, by name */
function zipEntries(TestResponse $response): array
{
    $file = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($file))->toBeTrue();
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($file);

    return $entries;
}

describe('audiences', function () {
    it('makes a public link without expiry from a v1.5 body, with a player and no downloads', function () {
        $share = shareOf($this, ['audience' => 'public']);

        expect($share)->toMatchArray(['kind' => 'session', 'session_id' => $this->session->id, 'client_id' => null, 'audience' => 'public',
            'people' => null, 'allow_download' => false, 'include_audio' => false, 'has_password' => false, 'expires_at' => null, 'active' => true]);
        $page = browser($this)->get(pathOf($share))->assertOk()->assertSee('Secret words of Budget review');
        expect($page->getContent())->toContain('<audio')->not->toContain('/download');
        browser($this)->get(pathOf($share).'/audio')->assertOk();
        browser($this)->get(pathOf($share).'/download')->assertNotFound();
    });

    it('keeps the v1.1 body as it was: one day, no player unless include_audio, which also allows downloads', function () {
        $old = shareOf($this, []);
        expect(Carbon::parse($old['expires_at'])->diffInHours(now(), true))->toBeGreaterThan(23.9)->and($old['include_audio'])->toBeFalse();
        expect(browser($this)->get(pathOf($old))->getContent())->not->toContain('<audio');

        $withAudio = shareOf($this, ['expires_in' => 3600, 'include_audio' => true]);
        expect($withAudio)->toMatchArray(['allow_download' => true, 'include_audio' => true]);
        browser($this)->get(pathOf($withAudio).'/download')->assertOk();
        // A password alone is a password link, as in v1.1.
        expect(shareOf($this, ['expires_in' => 3600, 'password' => 'secret12']))->toMatchArray(['audience' => 'password', 'has_password' => true]);
    });

    it('asks a password link for its password, and refuses one without a password', function () {
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['audience' => 'password'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $share = shareOf($this, ['audience' => 'password', 'password' => 'secret12', 'allow_download' => true]);

        browser($this)->get(pathOf($share))->assertOk()->assertSee('This transcript is protected')->assertDontSee('Secret words');
        browser($this)->get(pathOf($share).'/download')->assertNotFound();
        browser($this)->post(pathOf($share), ['password' => 'secret12'])->assertRedirect(pathOf($share));
        browser($this)->get(pathOf($share))->assertOk()->assertSee('Secret words of Budget review');
        browser($this)->get(pathOf($share).'/download')->assertOk();
    });

    it('opens a people-only link only for a signed-in person with one of its emails', function () {
        $ana = User::factory()->create(['email' => 'ana@example.com']);
        $omar = User::factory()->create(['email' => 'omar@example.com']);
        $share = shareOf($this, ['audience' => 'people', 'people' => ['ANA@example.com', 'ana@example.com', 'sara@example.com'], 'allow_download' => true]);
        expect($share['people'])->toBe(['ana@example.com', 'sara@example.com'])->and($share['has_password'])->toBeFalse();

        $anonymous = browser($this)->get(pathOf($share))->assertOk()->assertSee('Sign in to open this link');
        expect($anonymous->getContent())->not->toContain('Budget review')->not->toContain('Secret words');
        browser($this)->get(pathOf($share).'/audio')->assertNotFound();
        browser($this)->get(pathOf($share).'/download')->assertNotFound();
        // The password form does nothing for a people-only link.
        browser($this)->post(pathOf($share), ['password' => 'anything'])->assertNotFound();

        $this->actingAs($omar, 'web');
        $refused = $this->get(pathOf($share))->assertForbidden()->assertSee('This link was not shared with you')->assertSee('omar@example.com');
        expect($refused->getContent())->not->toContain('Budget review')->not->toContain('Secret words');
        $this->get(pathOf($share).'/audio')->assertNotFound();
        $this->get(pathOf($share).'/download')->assertNotFound();

        $this->actingAs($ana, 'web');
        $this->get(pathOf($share))->assertOk()->assertSee('Secret words of Budget review');
        $this->get(pathOf($share).'/audio')->assertOk();
        expect(zipEntries($this->get(pathOf($share).'/download')->assertOk()))->toHaveCount(2);
    });

    it('validates the people of a people-only link', function () {
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['audience' => 'people'])->assertStatus(422);
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['audience' => 'people', 'people' => ['not an email']])->assertStatus(422);
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['audience' => 'people', 'people' => array_map(fn (int $i): string => "p{$i}@example.com", range(1, 21))])->assertStatus(422);
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['audience' => 'everyone'])->assertStatus(422);
    });
});

describe('downloads and expiry', function () {
    it('downloads the decrypted audio and the transcript in one ZIP, in the link view', function () {
        $share = shareOf($this, ['allow_download' => true, 'audience' => 'public']);
        $response = browser($this)->get(pathOf($share).'/download')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/zip')
            ->and($response->headers->get('Content-Disposition'))->toContain('Budget review')
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
        $entries = zipEntries($response);
        $audio = collect($entries)->first(fn (string $body, string $name): bool => str_ends_with($name, '.ogg'));
        $text = collect($entries)->first(fn (string $body, string $name): bool => str_ends_with($name, '.txt'));
        expect($audio)->toBe($this->audio)->and($text)->toContain('Me: Secret words of Budget review');
        expect(ShareLinkView::query()->where('outcome', 'download')->count())->toBe(1)
            ->and($this->workFiles())->toBe([]);
    });

    it('puts the audio in the ZIP as it is, without packing it again on every download', function () {
        $silence = 'OggS'.str_repeat("\0", 200_000);
        $this->session = sharedRecording($this, 'Quiet call', $silence);
        $response = browser($this)->get(pathOf(shareOf($this, ['allow_download' => true])).'/download')->assertOk();

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $audio = collect(range(0, $zip->numFiles - 1))->map(fn (int $i): array => (array) $zip->statIndex($i))
            ->first(fn (array $entry): bool => str_ends_with((string) $entry['name'], '.ogg'));
        $zip->close();
        expect($audio)->toMatchArray(['comp_method' => ZipArchive::CM_STORE, 'comp_size' => strlen($silence)])
            ->and(zipEntries($response)[$audio['name']])->toBe($silence);
    });

    it('writes the transcript of the ZIP in the link view once that view is ready', function () {
        $final = $this->session->finalTranscript()->firstOrFail();
        $final->update(['meta' => ['views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($final), 'urdu' => ['بجٹ کی باتیں']]]]);
        // An English transcript can be shown in Urdu.
        $share = shareOf($this, ['allow_download' => true, 'view' => 'urdu']);

        $text = collect(zipEntries(browser($this)->get(pathOf($share).'/download')->assertOk()))
            ->first(fn (string $body, string $name): bool => str_ends_with($name, '.txt'));
        expect($text)->toContain('بجٹ کی باتیں')->not->toContain('Secret words');
    });

    it('takes an expiry date, refuses one in the past, and stops at it', function () {
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['expires_at' => now()->subMinute()->toIso8601String()])->assertStatus(422);
        $share = shareOf($this, ['expires_at' => now()->addHours(2)->toIso8601String()]);
        expect(Carbon::parse($share['expires_at'])->diffInMinutes(now()->addHours(2), true))->toBeLessThan(1);

        browser($this)->get(pathOf($share))->assertOk();
        $this->travel(3)->hours();
        browser($this)->get(pathOf($share))->assertNotFound()->assertSee('no longer available');
    });

    it('counts links without an expiry against the 20 active links', function () {
        for ($i = 0; $i < ShareLinks::MAX_ACTIVE_PER_SESSION; $i++) {
            shareOf($this, ['audience' => 'public']);
        }
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['audience' => 'public'])->assertStatus(409)->assertJsonPath('code', 'too_many_shares');
    });
});

describe('project links', function () {
    beforeEach(function () {
        $this->project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $this->sub = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Design', 'parent_id' => $this->project->id]);
        $this->older = sharedRecording($this, 'Kick-off', Fixtures::audio(3000), $this->project, ['started_at' => now()->subDays(2)]);
        $this->newer = sharedRecording($this, 'Design review', Fixtures::audio(3100), $this->sub, ['started_at' => now()->subDay()]);
    });

    it('lists the project recordings newest first, each opening like a recording link', function () {
        $share = shareOf($this, ['audience' => 'public', 'allow_download' => true], "/api/clients/{$this->project->id}/shares");
        expect($share['url'])->toStartWith(url('/p/'))->and($share)->toMatchArray(['kind' => 'project', 'client_id' => $this->project->id, 'session_id' => null]);

        $page = browser($this)->get(pathOf($share))->assertOk()->assertSeeInOrder(['Acme', 'Design review', 'Kick-off'])
            ->assertDontSee('Budget review')->assertDontSee('Secret words');
        expect($page->getContent())->toContain('echoledger://open?link='.rawurlencode(url(pathOf($share))));
        browser($this)->get(pathOf($share).'/'.$this->newer->id)->assertOk()->assertSee('Secret words of Design review')->assertSee('All recordings');
        browser($this)->get(pathOf($share).'/'.$this->newer->id.'/audio')->assertOk();
        expect(zipEntries(browser($this)->get(pathOf($share).'/'.$this->older->id.'/download')->assertOk()))->toHaveCount(2);
        // A recording outside the project is not reachable through it.
        browser($this)->get(pathOf($share).'/'.$this->session->id)->assertNotFound();
        browser($this)->get(pathOf($share).'/'.$this->session->id.'/audio')->assertNotFound();
        expect(ShareLink::query()->sole()->view_count)->toBe(1)
            ->and(ShareLinkView::query()->where('outcome', 'opened')->count())->toBe(1);
    });

    it('stops working when revoked, expired or when the project is deleted', function () {
        $revoked = shareOf($this, ['audience' => 'public'], "/api/clients/{$this->project->id}/shares");
        $expiring = shareOf($this, ['expires_in' => 3600], "/api/clients/{$this->project->id}/shares");
        $live = shareOf($this, ['audience' => 'public'], "/api/clients/{$this->sub->id}/shares");
        $this->deleteJson('/api/shares/'.$revoked['id'])->assertNoContent();
        $this->deleteJson("/api/clients/{$this->sub->id}")->assertOk();

        browser($this)->get(pathOf($revoked))->assertNotFound();
        browser($this)->get(pathOf($revoked).'/'.$this->older->id)->assertNotFound();
        browser($this)->get(pathOf($live))->assertNotFound();
        browser($this)->get(pathOf($expiring))->assertOk();
        $this->travel(2)->hours();
        browser($this)->get(pathOf($expiring))->assertNotFound();
        expect(ShareLink::query()->where('client_id', $this->sub->id)->exists())->toBeFalse();
    });

    it('keeps a password or people check on the project page and its recordings', function () {
        $share = shareOf($this, ['audience' => 'password', 'password' => 'secret12'], "/api/clients/{$this->project->id}/shares");
        browser($this)->get(pathOf($share))->assertOk()->assertSee('These recordings are protected')->assertDontSee('Kick-off');
        browser($this)->get(pathOf($share).'/'.$this->older->id)->assertOk()->assertDontSee('Secret words');
        browser($this)->post(pathOf($share), ['password' => 'secret12'])->assertRedirect(pathOf($share));
        browser($this)->get(pathOf($share).'/'.$this->older->id)->assertOk()->assertSee('Secret words of Kick-off');

        $people = shareOf($this, ['audience' => 'people', 'people' => ['ana@example.com']], "/api/clients/{$this->project->id}/shares");
        $this->actingAs(User::factory()->create(['email' => 'eve@example.com']), 'web');
        $this->get(pathOf($people))->assertForbidden()->assertDontSee('Kick-off');
        $this->get(pathOf($people).'/'.$this->older->id)->assertForbidden()->assertDontSee('Secret words');
    });

    it('answers client_not_found for another workspace\'s project', function () {
        $foreign = Client::factory()->create(['user_id' => User::factory()]);

        $this->postJson("/api/clients/{$foreign->id}/shares", ['audience' => 'public'])->assertNotFound()->assertJsonPath('code', 'client_not_found');
    });
});

describe('listing', function () {
    it('lists every link of the workspace newest first, narrows by recording or project, and hides other workspaces', function () {
        $project = Client::factory()->create(['user_id' => $this->device->user_id]);
        $first = shareOf($this, ['audience' => 'public']);
        $this->travel(1)->minutes();
        $second = shareOf($this, ['audience' => 'public'], "/api/clients/{$project->id}/shares");
        [$other, $otherToken] = $this->enroll();
        $foreignSession = CaptureSession::factory()->create(['user_id' => $other->user_id, 'device_id' => $other->id]);
        ['link' => $foreignLink] = app(ShareLinks::class)->create($foreignSession, 3600, null, false, 'Other');
        $this->asToken($this->token);

        expect(array_column($this->getJson('/api/shares')->assertOk()->json('data'), 'id'))->toBe([$second['id'], $first['id']])
            ->and(array_column($this->getJson('/api/shares?session_id='.$this->session->id)->json('data'), 'id'))->toBe([$first['id']])
            ->and(array_column($this->getJson('/api/shares?client_id='.$project->id)->json('data'), 'id'))->toBe([$second['id']]);
        $this->deleteJson('/api/shares/'.$foreignLink->id)->assertNotFound()->assertJsonPath('code', 'share_not_found');
        $this->deleteJson('/api/shares/'.$second['id'])->assertNoContent();
        expect($this->getJson('/api/shares?client_id='.$project->id)->json('data.0.active'))->toBeFalse();
    });

    it('throttles every public page with the share limiters', function () {
        foreach (['share.show', 'share.download', 'share.project', 'share.project.session', 'share.project.audio', 'share.project.download'] as $name) {
            expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:share');
        }
        expect(Route::getRoutes()->getByName('share.project.unlock')->gatherMiddleware())->toContain('throttle:share-password');
    });
});

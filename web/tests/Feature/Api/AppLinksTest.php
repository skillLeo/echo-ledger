<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\SentItem;
use App\Models\User;
use App\Services\ShareLinks;
use App\Services\Sharing\RequestLinks;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Links in the apps (since v1.5)": /.well-known/assetlinks.json, the "Open in EchoLedger" link on the
 * pages, and GET /api/links/resolve.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    $this->session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Completed]);
    $this->project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
    $this->other = User::factory()->create(['email' => 'other@example.com']);
    $this->otherSession = CaptureSession::factory()->create(['user_id' => $this->other->id, 'status' => SessionStatus::Completed]);
    $this->otherProject = Client::factory()->create(['user_id' => $this->other->id]);
});

function resolveLink(object $test, string $url): TestResponse
{
    return $test->asToken($test->token)->getJson('/api/links/resolve?url='.rawurlencode($url));
}

it('publishes the Android App Links statement as JSON', function () {
    $response = $this->get('/.well-known/assetlinks.json')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->json())->toBe([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => 'com.echoledger.mobile',
                'sha256_cert_fingerprints' => ['34:AC:18:19:18:77:F6:C3:12:A2:88:3E:07:C8:40:0B:85:C4:71:62:A4:0F:77:B4:FE:2D:4F:27:08:AF:F1:B6']],
        ]]);
    config(['echoledger.android_app.fingerprints' => ['AA:BB', 'CC:DD']]);
    expect($this->get('/.well-known/assetlinks.json')->json('0.target.sha256_cert_fingerprints'))->toBe(['AA:BB', 'CC:DD']);
});

it('offers to open a share page in the app', function () {
    ['url' => $url] = app(ShareLinks::class)->create($this->session, 3600, null, false, 'Phone');
    $this->app['auth']->forgetGuards();

    $page = $this->withHeaders(['Authorization' => ''])->get(parse_url($url, PHP_URL_PATH))->assertOk();
    expect($page->getContent())->toContain('href="echoledger://open?link='.rawurlencode($url).'"')->toContain('Open in EchoLedger');
});

describe('resolve', function () {
    it('opens the workspace\'s recordings, projects and request links in place', function () {
        ['url' => $recording] = app(ShareLinks::class)->create($this->session, 3600, null, false, 'Phone');
        ['url' => $project] = app(ShareLinks::class)->make($this->project, ['audience' => 'people', 'people' => ['someone@example.com'], 'include_audio' => true, 'allow_download' => false, 'expires_at' => null], 'Phone');
        ['link' => $request, 'url' => $requestUrl] = app(RequestLinks::class)->create($this->device->workspace_id, $this->device->user_id, null, 'Files', null, 5, 10);
        $this->session->update(['client_id' => $this->project->id]);

        resolveLink($this, $recording)->assertOk()->assertExactJson(['kind' => 'session', 'session_id' => $this->session->id]);
        resolveLink($this, $project)->assertOk()->assertExactJson(['kind' => 'project', 'client_id' => $this->project->id]);
        resolveLink($this, $project.'/'.$this->session->id)->assertOk()->assertExactJson(['kind' => 'session', 'session_id' => $this->session->id]);
        resolveLink($this, $requestUrl)->assertOk()->assertExactJson(['kind' => 'request', 'request_link_id' => $request->id]);
    });

    it('opens what a teammate sent as its item of Shared with me: a recording, a project, a recording of a sent project', function () {
        ['url' => $recording] = app(ShareLinks::class)->create($this->otherSession, 3600, null, false, 'Other');
        ['url' => $project] = app(ShareLinks::class)->make($this->otherProject, ['audience' => 'public', 'include_audio' => true, 'allow_download' => false, 'expires_at' => null], 'Other');
        resolveLink($this, $recording)->assertNotFound();
        resolveLink($this, $project)->assertNotFound();

        $me = User::query()->findOrFail($this->device->user_id);
        $sentRecording = SentItem::query()->create(['workspace_id' => $this->otherSession->workspace_id, 'from_user_id' => $this->other->id, 'to_user_id' => $me->id, 'kind' => 'session', 'capture_session_id' => $this->otherSession->id, 'title' => 'Call']);
        $item = SentItem::query()->create(['workspace_id' => $this->otherProject->workspace_id, 'from_user_id' => $this->other->id, 'to_user_id' => $me->id, 'kind' => 'project', 'client_id' => $this->otherProject->id, 'title' => 'Project']);

        // Not in the caller's library, so never `session`: the app opens it from Shared with me (docs/API.md).
        resolveLink($this, $recording)->assertOk()->assertExactJson(['kind' => 'shared', 'shared_id' => $sentRecording->id]);
        resolveLink($this, $project)->assertOk()->assertExactJson(['kind' => 'shared', 'shared_id' => $item->id]);

        // A recording of the sent project, from the project page (/p/{token}/{recording}): the project's item.
        $inProject = CaptureSession::factory()->create(['user_id' => $this->other->id, 'workspace_id' => $this->otherProject->workspace_id,
            'client_id' => $this->otherProject->id, 'status' => SessionStatus::Completed]);
        resolveLink($this, $project.'/'.$inProject->id)->assertOk()->assertExactJson(['kind' => 'shared', 'shared_id' => $item->id]);
    });

    it('answers not_found for another workspace, a revoked, expired or deleted target, and anything that is not a link here', function () {
        ['url' => $foreign] = app(RequestLinks::class)->create($this->otherProject->workspace_id, $this->other->id, null, 'Theirs', null, 5, 10);
        ['link' => $revoked, 'url' => $revokedUrl] = app(ShareLinks::class)->create($this->session, 3600, null, false, 'Phone');
        app(ShareLinks::class)->revoke($revoked);
        ['url' => $expiring] = app(ShareLinks::class)->create($this->session, 3600, null, false, 'Phone');
        $binned = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'status' => SessionStatus::Completed]);
        ['url' => $deleted] = app(ShareLinks::class)->create($binned, 3600, null, false, 'Phone');
        $binned->delete();

        foreach ([$foreign, $revokedUrl, $deleted, url('/s/'.str_repeat('A', 43)), url('/x/'.str_repeat('A', 43)), 'https://elsewhere.example/s/'.basename($expiring), 'not a link'] as $url) {
            resolveLink($this, $url)->assertNotFound()->assertJsonPath('code', 'not_found');
        }
        resolveLink($this, $expiring)->assertOk();
        $this->travel(2)->hours();
        resolveLink($this, $expiring)->assertNotFound();
        $this->asToken($this->token)->getJson('/api/links/resolve')->assertStatus(422);
    });
});

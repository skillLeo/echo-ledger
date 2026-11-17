<?php

declare(strict_types=1);

use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\MissedRecording;
use App\Models\Screen;
use App\Models\SentItem;
use App\Models\SessionLabel;
use App\Models\SessionMarker;
use App\Models\ShareCheckResult;
use App\Models\UpgradeRequest;
use App\Models\UpgradeRequestProof;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceInviteLink;
use App\Services\InterfaceFlags;
use App\Services\ShareLinks;
use App\Services\Sharing\RequestLinks;
use App\Services\Workspaces\Workspaces;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| The isolation cases, shared by WorkspaceIsolationTest (workspace A against workspace B, B20) and PrivacyTest (an owner
| against a member of the same workspace, v1.5): the world, every id and secret of the other side, and one case per API
| route.
*/

/** API routes outside the device token, each with the reason it is safe without a workspace. */
const ISO_API_BYPASS = [
    'api.devices.register' => 'pairing: the one-time code decides the workspace',
    'api.auth.options' => 'sign-in options: server-wide settings (password, Google, sign-up), no workspace data',
    'api.auth.password' => 'signing in: the account decides the workspace (its current one)',
    'api.auth.device.token' => 'signing in: the approved one-time code decides the workspace',
    'api.auth.start' => 'signing in (v1.6): whether an address has an account and its ways in, no workspace data',
    'api.auth.code' => 'signing in (v1.6): emails a code to the address typed, no workspace data',
    'api.auth.code.verify' => 'signing in (v1.6): the emailed code decides the account, then its current workspace',
    'api.auth.sign-up' => 'signing up (v1.6): the new account and its own new workspace',
    'api.webhooks.stt' => 'provider callback: found by transcript id and verified signature',
    'api.recordings.audio' => 'signed, expiring URL issued to a device of the workspace',
    // Since v1.9 (L1): the Live Assist relay, HMAC-signed with the server's own secret, never a device token; it finds
    // a session by its id, which only a relay token the server minted for that session carries.
    'api.internal.assist.providers' => 'the relay: provider keys and the model catalog, no workspace data',
    'api.internal.assist.usage' => 'the relay: a suggestion\'s cost, found by the session id of its signed token',
    'api.internal.assist.suggestions' => 'the relay: a suggestion\'s words, found by the session id of its signed token',
    'api.internal.assist.facts' => 'the relay: the facts ledger, found by the session id of its signed token',
    'api.internal.assist.summary' => 'the relay: the running summary, found by the session id of its signed token',
];

/** Named web routes outside Filament, each with the reason it is safe without a workspace. */
const ISO_WEB_BYPASS = [
    'share.show' => 'public share link: the token is the only key',
    'share.unlock' => 'public share link: the token is the only key',
    'share.audio' => 'public share link: the token is the only key',
    'share.download' => 'public share link: the token is the only key',
    'share.project' => 'public project link: the token is the only key',
    'share.project.unlock' => 'public project link: the token is the only key',
    'share.project.session' => 'public project link: the token is the only key',
    'share.project.audio' => 'public project link: the token is the only key',
    'share.project.download' => 'public project link: the token is the only key',
    'request.show' => 'public request link: the token is the only key',
    'request.files.store' => 'public request link: the token is the only key',
    'request.files.chunk' => 'public request link: the token is the only key',
    'request.files.complete' => 'public request link: the token is the only key',
    'app-links' => 'Android App Links statement: the app package and signing keys, no workspace data',
    'site.home' => 'public landing page: no workspace data',
    'site.pricing' => 'public pricing page (v2.0): the plans on offer, server-wide, no workspace data',
    'legacy.profile' => 'the account page\'s old address (before v1.7): a redirect to Settings, no data',
    'legacy.security' => 'the security centre\'s old address (before v1.7): a redirect to Settings, no data',
    'legacy.console' => 'the app\'s old addresses under /admin (before v1.6): a redirect to the same path under /app, no data',
    'downloads' => 'public downloads page: the apps\' builds, server-wide, no workspace data',
    'downloads.android' => 'public update manifest of the phone app, server-wide',
    'downloads.desktop' => 'public update manifest of the desktop app, server-wide',
    'downloads.file' => 'a published build by its file name, server-wide',
    'passkeys.login.options' => 'sign-in, before any workspace',
    'passkeys.login' => 'sign-in, before any workspace',
    'screens.media' => 'signed, expiring URL of a screen moment issued to a device of the workspace',
    'auth.google.redirect' => 'sign-in with Google, before any workspace',
    'auth.google.callback' => 'sign-in with Google, before any workspace',
    'auth.google.confirm' => 'sign-in with Google, before any workspace',
    'auth.google.confirm.store' => 'sign-in with Google, before any workspace',
    'device.sign-in' => 'the apps\' browser sign-in: the signed-in person approves a device for their own account',
    'device.sign-in.approve' => 'the apps\' browser sign-in: the signed-in person approves a device for their own account',
    'invitations.show' => 'signed link of one invitation: the token and the invited address are the only keys',
    // Since v1.8 P6.3: an invite link's page; its secret token decides the team, and joining needs a signed-in account.
    'join.show' => 'invite link: the secret token decides the team, nothing of any workspace shows but its name',
    'join.resume' => 'invite link: the one this visitor opened, from their own session (encrypted)',
    'join.accept' => 'invite link: the one this visitor opened, from their own session; the signed-in account joins it',
    'invitations.accept' => 'signed link of one invitation: the token and the invited address are the only keys',
    'account.delete' => 'deleting one\'s own account: the signed-in person only, no workspace data',
    'account.delete.store' => 'deleting one\'s own account: the signed-in person only, no workspace data',
    'account.export.download' => 'signed, expiring link of one person\'s own data export',
    'legal.terms' => 'public legal page: no workspace data',
    'sign-up' => 'a redirect to the sign-up or sign-in page, before any workspace',
    'legal.privacy' => 'public legal page: no workspace data',
    'mcp.oauth.protected-resource' => 'the connector\'s OAuth discovery: addresses and the scope, no workspace data',
    'mcp.oauth.protected-resource.nested' => 'the connector\'s OAuth discovery: addresses and the scope, no workspace data',
    'mcp.oauth.authorization-server' => 'the connector\'s OAuth discovery: addresses and the scope, no workspace data',
    'mcp.oauth.authorization-server.nested' => 'the connector\'s OAuth discovery: addresses and the scope, no workspace data',
    'mcp.oauth.register' => 'dynamic client registration: a name and redirect addresses, no account or workspace data',
    'passport.authorizations.authorize' => 'the connector\'s sign-in: the signed-in person is asked about their own account',
    'passport.authorizations.approve' => 'the connector\'s sign-in: the signed-in person approves an app for their own account',
    'passport.authorizations.deny' => 'the connector\'s sign-in: the signed-in person refuses an app for their own account',
    'passport.token' => 'the connector\'s sign-in: the one-time code and its verifier decide the person',
    'mcp' => 'the connector: a person\'s token, and its tools ask as the apps do (ConnectorToolsTest)',
];

const ISO_MISSING_EXPORT = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

const ISO_SECRETS = ['BOB-SECRET-TITLE', 'BOBSECRETTRANSCRIPT', 'BOB-SECRET-CLIENT', 'BOB-SECRET-DEVICE', 'BOBSECRETTERM', 'bobsecretlabel', 'BOBSECRETNOTE'];

/**
 * Alice (a super admin, so every console page opens) and Bob with everything a person can have. Bob has his own workspace,
 * or with `$sameWorkspace` he is a member of Alice's (v1.5: private all the same).
 *
 * @return array<string, mixed>
 */
function isoWorld(bool $sameWorkspace = false): array
{
    $alice = Fx::superAdmin();
    $aliceDevice = Fx::device($alice, ['name' => 'Alice phone']);
    Fx::readyRecording(Fx::session($alice, $aliceDevice, ['title' => 'Alice call']), 'alice audio');

    $bob = Fx::user();
    if ($sameWorkspace) {
        $team = Workspace::query()->findOrFail($alice->current_workspace_id);
        app(Workspaces::class)->addMember($team, $bob);
        app(Workspaces::class)->switchTo($bob, $team);
        $bob->refresh();
    }
    $device = Fx::device($bob, ['name' => 'BOB-SECRET-DEVICE']);
    $client = Fx::client($bob, 'BOB-SECRET-CLIENT');
    $client->keyterms()->create(['term' => 'BOBSECRETTERM']);
    $session = Fx::session($bob, $device, ['title' => 'BOB-SECRET-TITLE', 'client_id' => $client->id]);
    $recording = Fx::readyRecording($session, 'BOB-AUDIO-'.random_bytes(64));
    $transcript = Fx::transcript($session, 'BOBSECRETTRANSCRIPT spoken here');
    $marker = SessionMarker::query()->create(['capture_session_id' => $session->id, 'at_ms' => 1000, 'note' => 'BOBSECRETNOTE', 'source' => 'device']);
    SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => 'bobsecretlabel']);
    $screen = Screen::query()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => 1000, 'width' => 1, 'height' => 1,
        'trigger' => 'manual', 'sha256' => str_repeat('b', 64), 'mime' => 'image/png', 'bytes' => 1, 'ocr_text' => 'BOBSECRETNOTE on the screen']);
    ['link' => $share, 'url' => $shareUrl] = app(ShareLinks::class)->create($session, 3600, null, false, 'Bob');
    $binned = Fx::session($bob, $device, ['title' => 'BOB-SECRET-TITLE binned']);
    $binned->delete();
    $uploading = Fx::session($bob, $device, ['title' => 'BOB-SECRET-TITLE uploading']);
    $upload = Fx::pendingRecording($uploading, str_repeat('b', 2048), 1024);
    ['link' => $requestLink] = app(RequestLinks::class)->create($client->workspace_id, $bob->id, $client, 'BOB-SECRET-TITLE request', null, 20, 100);
    $sent = SentItem::query()->create(['workspace_id' => $session->workspace_id, 'from_user_id' => $bob->id, 'to_user_id' => Fx::user()->id, 'kind' => 'session', 'capture_session_id' => $session->id, 'title' => 'BOB-SECRET-TITLE sent', 'sent_at' => now()]);
    // Since v1.7: Bob's upgrade request with a receipt not sent yet.
    $upgrade = UpgradeRequest::query()->create(['user_id' => $bob->id, 'workspace_id' => $bob->current_workspace_id, 'plan' => 'pro', 'status' => UpgradeRequest::PENDING]);
    $proof = UpgradeRequestProof::query()->create(['upgrade_request_id' => $upgrade->id, 'user_id' => $bob->id, 'kind' => 'image', 'mime' => 'image/webp', 'name' => 'BOB-SECRET-RECEIPT.png',
        'bytes' => 1, 'sha256' => str_repeat('c', 64), 'path' => 'payment-proofs/bob.elr', 'thumb_path' => 'payment-proofs/bob-thumb.elr']);
    // Since v1.8: a call Bob's phone saw but did not record.
    $missed = MissedRecording::query()->create(['user_id' => $bob->id, 'device_id' => $device->id, 'workspace_id' => $device->workspace_id, 'client_ref' => '7d1e0c2a-5b3f-4e8d-9a61-0f2b4c6d8e10',
        'platform' => 'android', 'source_app' => 'whatsapp', 'call_started_at' => now()->subHour(), 'call_duration_s' => 60, 'reason' => 'permission']);
    // Since v1.9 (L1): Live Assist on for everyone, and Bob's prep and session with words of his.
    app(InterfaceFlags::class)->set('live_assist', true, 0, [], []);
    $assistPrep = AssistPrep::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $bob->id, 'meeting_type' => 'boss', 'title' => 'BOB-SECRET-TITLE prep',
        'hint_level' => 'full', 'mode' => 'fast', 'language_help' => 'off', 'status' => 'draft']);
    $assistSession = AssistSession::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $bob->id, 'device_id' => $device->id, 'mode' => 'fast',
        'platform' => 'android', 'status' => 'active', 'started_at' => now(), 'last_token_at' => now(), 'summary' => 'BOBSECRETNOTE summary']);
    AssistTurn::query()->create(['assist_session_id' => $assistSession->id, 'client_ref' => 'bob-1', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 10, 'text' => 'BOBSECRETTRANSCRIPT asked']);
    // Since v1.8 P6.3: an invitation and an invite link of a team of Bob's (never Alice's workspace).
    $bobTeam = Workspace::query()->create(['name' => 'BOB-SECRET-TEAM', 'plan_id' => Workspace::query()->findOrFail($device->workspace_id)->plan_id]);
    $bobInvitation = WorkspaceInvitation::query()->create(['workspace_id' => $bobTeam->id, 'email' => 'bob-secret-invitee@example.test', 'role' => 'member',
        'token_hash' => hash('sha256', 'bob-invitation'), 'invited_by' => $bob->id, 'expires_at' => now()->addDay()]);
    $bobLink = WorkspaceInviteLink::query()->create(['workspace_id' => $bobTeam->id, 'role' => 'member', 'token_hash' => hash('sha256', 'bob-link'),
        'created_by' => $bob->id, 'expires_at' => now()->addDay()]);
    app('auth')->forgetGuards();

    return compact('alice', 'aliceDevice', 'bob', 'device', 'client', 'session', 'recording', 'transcript', 'marker', 'share', 'binned', 'upload', 'requestLink', 'shareUrl', 'screen', 'sent', 'upgrade', 'proof', 'missed', 'assistPrep', 'assistSession', 'bobInvitation', 'bobLink');
}

/** Every id of workspace B; none may appear in an answer to workspace A. */
function isoIds(array $b): array
{
    return [$b['device']->id, $b['client']->id, $b['session']->id, $b['recording']->id, $b['transcript']->id, $b['marker']->id, $b['share']->id, $b['binned']->id, $b['upload']->id, $b['requestLink']->id, $b['screen']->id, $b['sent']->id, $b['upgrade']->id, $b['proof']->id, $b['missed']->id];
}

/**
 * API cases by route name: [method, uri, body, expectation]. `404` = the documented not-found answer; `list` = a
 * normal answer for A's own data; a closure decides for answers with a special shape.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int|string|Closure(TestResponse): bool}>
 */
function isoApiCases(array $b): array
{
    $s = $b['session']->id;
    $t = $b['transcript']->id;

    return [
        'api.devices.rotate' => ['POST', '/api/devices/rotate-token', [], 'list'],
        // Writes only about the calling device: no id in the request, the device decides the workspace.
        'api.devices.report' => ['POST', '/api/devices/report', ['platform' => 'android', 'checks' => [['key' => 'voice_notes', 'state' => 'ok']]], 'list'],
        'api.config' => ['GET', '/api/config', [], 'list'],
        // Since v1.8.1: a Share Check result about the calling device only (ids in the body are never read; since the
        // audit's S3 its platform is the device's own), and the server-wide sharing-bar patterns. No route reads results back.
        'api.diagnostics.share-checks' => ['POST', '/api/diagnostics/share-checks', ['platform' => $b['aliceDevice']->platform->value, 'os_build' => '16', 'app_version' => '1.8.1', 'method' => 'recording_callback', 'result' => 'pass', 'device_id' => $b['device']->id, 'user_id' => $b['bob']->id],
            fn (TestResponse $r): bool => $r->status() === 201 && ShareCheckResult::query()->where('device_id', $b['device']->id)->doesntExist()],
        'api.config.share-bars' => ['GET', '/api/config/share-bars', [], 'list'],
        // Since v1.8: a call the calling device saw but did not record. Ids in the body are never read, and B's own
        // client_ref is a new event of A's device: never B's row, nor its id.
        'api.devices.missed-recordings' => ['POST', '/api/devices/missed-recordings', ['client_ref' => $b['missed']->client_ref, 'platform' => 'android', 'source_app' => 'whatsapp',
            'call_started_at' => now()->subMinutes(10)->toIso8601String(), 'call_duration_s' => 30, 'reason' => 'helper_not_armed',
            'id' => $b['missed']->id, 'device_id' => $b['device']->id, 'user_id' => $b['bob']->id, 'workspace_id' => $b['device']->workspace_id],
            fn (TestResponse $r): bool => $r->status() === 201 && $r->json('data.id') !== $b['missed']->id && unscoped(fn (): bool => MissedRecording::query()->where('device_id', $b['device']->id)->count() === 1
                && MissedRecording::query()->whereKey($b['missed']->id)->value('reason') === 'permission'
                && MissedRecording::query()->where('device_id', $b['aliceDevice']->id)->where('client_ref', $b['missed']->client_ref)->exists())],
        'api.security' => ['GET', '/api/security', [], 'list'],
        'api.clients' => ['GET', '/api/clients', [], 'list'],
        'api.stt.token' => ['POST', '/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $s], 404],
        'api.usage.store' => ['POST', '/api/usage', ['report_id' => 'iso-report-1', 'use_case' => 'dictation', 'provider' => 'assemblyai', 'session_id' => $s, 'streamed_ms' => 1000, 'channels' => 1],
            fn (TestResponse $r): bool => $r->status() === 201 && $r->json('report.session_id') === null],
        'api.sessions.store' => ['POST', '/api/sessions', Fx::sessionPayload(['client_id' => $b['client']->id]), 404],
        'api.sessions.finish' => ['POST', "/api/sessions/{$s}/finish", ['ended_at' => '2026-10-24T09:10:00Z', 'duration_ms' => 1000, 'artifacts' => [Fx::artifact()]], 404],
        'api.sessions.recordings' => ['POST', "/api/sessions/{$s}/recordings", Fx::artifact(['role' => 'channel_1']), 404],
        'api.sessions.transcripts' => ['POST', "/api/sessions/{$s}/transcripts", ['kind' => 'live', 'provider' => 'x', 'segments' => Fx::segments('HIJACK')], 404],
        'api.sessions.destroy' => ['DELETE', "/api/sessions/{$s}", [], 404],
        'api.sessions.update' => ['PATCH', "/api/sessions/{$s}", ['title' => 'hijacked'], 404],
        'api.sessions.bulk' => ['POST', '/api/sessions/bulk', ['ids' => [$s], 'action' => 'delete'], fn (TestResponse $r): bool => $r->status() === 200 && $r->json('changed') === 0],
        'api.clients.store' => ['POST', '/api/clients', ['name' => 'Alice new client'], fn (TestResponse $r): bool => $r->status() === 201],
        'api.clients.update' => ['PATCH', "/api/clients/{$b['client']->id}", ['name' => 'hijacked'], 404],
        'api.clients.destroy' => ['DELETE', "/api/clients/{$b['client']->id}", [], 404],
        'api.clients.rules' => ['GET', "/api/clients/{$b['client']->id}/rules", [], 404],
        'api.clients.rules.store' => ['POST', "/api/clients/{$b['client']->id}/rules", ['kind' => 'title', 'value' => 'hijack'], 404],
        'api.clients.rules.destroy' => ['DELETE', "/api/clients/{$b['client']->id}/rules/1", [], 404],
        'api.clients.workspace' => ['GET', "/api/clients/{$b['client']->id}/workspace", [], 404],
        'api.clients.bundle' => ['GET', "/api/clients/{$b['client']->id}/bundle", [], 404],
        'api.clients.brief.update' => ['PUT', "/api/clients/{$b['client']->id}/brief", ['sections' => ['goal' => 'hijacked']], 404],
        'api.clients.brief.refresh' => ['POST', "/api/clients/{$b['client']->id}/brief/refresh", [], 404],
        'api.clients.memory.store' => ['POST', "/api/clients/{$b['client']->id}/memory", ['kind' => 'decision', 'text' => 'hijacked'], 404],
        'api.clients.memory.update' => ['PATCH', "/api/clients/{$b['client']->id}/memory/{$b['client']->id}", ['text' => 'hijacked'], 404],
        'api.clients.memory.destroy' => ['DELETE', "/api/clients/{$b['client']->id}/memory/{$b['client']->id}", [], 404],
        'api.clients.files.store' => ['POST', "/api/clients/{$b['client']->id}/files", ['text' => 'hijacked'], 404],
        'api.clients.files.destroy' => ['DELETE', "/api/clients/{$b['client']->id}/files/{$b['client']->id}", [], 404],
        'api.clients.ask' => ['POST', "/api/clients/{$b['client']->id}/ask", ['question' => 'What was agreed?'], 404],
        'api.sessions.notes' => ['GET', "/api/sessions/{$s}/notes", [], 404],
        'api.sessions.notes.make' => ['POST', "/api/sessions/{$s}/notes", [], 404],
        'api.transcripts.suggestions' => ['POST', "/api/transcripts/{$t}/suggestions/0", ['accept' => true], 404],
        'api.devices.revoke' => ['DELETE', "/api/devices/{$b['device']->id}", [], 404],
        'api.devices.rename' => ['PATCH', "/api/devices/{$b['device']->id}", ['name' => 'hijacked'], 404],
        'api.sessions.restore' => ['POST', "/api/sessions/{$b['binned']->id}/restore", [], 404],
        'api.bin.destroy' => ['DELETE', "/api/bin/{$b['binned']->id}", [], 404],
        'api.sessions.markers.store' => ['POST', "/api/sessions/{$s}/markers", ['at_ms' => 10], 404],
        'api.sessions.retranscribe' => ['POST', "/api/sessions/{$s}/retranscribe", ['language' => 'ur'], 404],
        'api.sessions.markers.destroy' => ['DELETE', "/api/sessions/{$s}/markers/{$b['marker']->id}", [], 404],
        'api.sessions.speakers' => ['PUT', "/api/sessions/{$s}/speakers", ['names' => ['A' => 'X']], 404],
        'api.sessions.shares.store' => ['POST', "/api/sessions/{$s}/shares", ['expires_in' => 3600], 404],
        'api.shares.destroy' => ['DELETE', "/api/shares/{$b['share']->id}", [], 404],
        'api.clients.shares.store' => ['POST', "/api/clients/{$b['client']->id}/shares", ['audience' => 'public'], 404],
        'api.shares' => ['GET', '/api/shares', [], 'list'],
        'api.sessions.send' => ['POST', "/api/sessions/{$s}/send", ['to' => [$b['bob']->email]], 404],
        'api.clients.send' => ['POST', "/api/clients/{$b['client']->id}/send", ['to' => [$b['bob']->email]], 404],
        // What was sent is the sender's own list: Bob's to someone is not Alice's to read or take back.
        'api.sent' => ['GET', "/api/sent?session_id={$s}", [], fn (TestResponse $r): bool => $r->status() === 200 && $r->json('data') === []],
        'api.sent.destroy' => ['DELETE', "/api/sent/{$b['sent']->id}", [], 404],
        // Shared with me is the person's own list: nothing of B was sent to A.
        'api.shared' => ['GET', '/api/shared-with-me', [], 'list'],
        'api.shared.save' => ['POST', "/api/shared-with-me/{$s}/save", [], 404],
        'api.shared.destroy' => ['DELETE', "/api/shared-with-me/{$s}", [], 404],
        'api.request-links.store' => ['POST', '/api/request-links', ['client_id' => $b['client']->id], 404],
        'api.request-links' => ['GET', '/api/request-links', [], 'list'],
        'api.request-links.destroy' => ['DELETE', "/api/request-links/{$b['requestLink']->id}", [], 404],
        // B's own share link is not A's to open in the app: the same 404 as an unknown link.
        'api.links.resolve' => ['GET', '/api/links/resolve?url='.rawurlencode($b['shareUrl']), [], 404],
        'api.vocabulary.keyterms' => ['POST', '/api/vocabulary/keyterms', ['term' => 'Hijack', 'client_id' => $b['client']->id], 404],
        'api.vocabulary.replacements' => ['POST', '/api/vocabulary/replacements', ['from' => 'a', 'to' => 'b', 'client_id' => $b['client']->id], 404],
        'api.transcripts.update' => ['PATCH', "/api/transcripts/{$t}", ['edits' => [['index' => 0, 'text' => 'hijacked']]], 404],
        'api.transcripts.replace' => ['POST', "/api/transcripts/{$t}/replace", ['find' => 'BOB', 'replace' => 'X'], 404],
        'api.transcripts.restore' => ['POST', "/api/transcripts/{$t}/restore", ['revision_id' => 1], 404],
        'api.library' => ['GET', '/api/library?since=1970-01-01T00:00:00Z&limit=100&include_deleted=1', [], 'list'],
        'api.library.views' => ['GET', '/api/library/views', [], 'list'],
        'api.bin' => ['GET', '/api/bin', [], 'list'],
        'api.storage' => ['GET', '/api/storage', [], fn (TestResponse $r): bool => $r->status() === 200 && $r->json('recordings_count') === 1],
        'api.activity' => ['GET', '/api/activity', [], 'list'],
        'api.activity.read' => ['POST', '/api/activity/read', [], 'list'],
        'api.sessions.export' => ['POST', '/api/sessions/export', ['ids' => [$s], 'format' => 'txt'],
            // Same answer as for unknown ids: nothing of the workspace matched.
            fn (TestResponse $r): bool => $r->status() === 409 && $r->json('code') === 'nothing_to_export'],
        'api.search' => ['GET', '/api/search?q=BOBSECRETTRANSCRIPT', [], 'list'],
        'api.sessions.show' => ['GET', "/api/sessions/{$s}", [], 404],
        'api.sessions.opened' => ['POST', "/api/sessions/{$s}/opened", [], 404],
        // Live Translate holds no workspace data; without a Soniox key it says so and leaks nothing of the other one.
        'api.live-translate.token' => ['POST', '/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en'],
            fn (TestResponse $r): bool => $r->status() === 503 && $r->json('code') === 'provider_not_configured'],
        'api.transcripts.show' => ['GET', "/api/transcripts/{$t}", [], 404],
        'api.transcripts.revisions' => ['GET', "/api/transcripts/{$t}/revisions", [], 404],
        'api.transcripts.export' => ['GET', "/api/transcripts/{$t}/export?format=txt", [], 404],
        'api.transcripts.views' => ['GET', "/api/transcripts/{$t}/views", [], 404],
        'api.transcripts.views.make' => ['POST', "/api/transcripts/{$t}/views", [], 404],
        'api.sessions.shares' => ['GET', "/api/sessions/{$s}/shares", [], 404],
        'api.vocabulary' => ['GET', '/api/vocabulary?client_id='.$b['client']->id, [], 404],
        'api.recordings.show' => ['GET', "/api/recordings/{$b['upload']->id}", [], 404],
        'api.recordings.chunks' => ['POST', "/api/recordings/{$b['upload']->id}/chunks", [], 404],
        'api.recordings.complete' => ['POST', "/api/recordings/{$b['upload']->id}/complete", [], 404],
        'api.sessions.screens.store' => ['POST', "/api/sessions/{$s}/screens", ['at_ms' => 0, 'width' => 1, 'height' => 1, 'trigger' => 'manual', 'sha256' => str_repeat('a', 64)], 404],
        'api.sessions.screens' => ['GET', "/api/sessions/{$s}/screens", [], 404],
        'api.sessions.screens.destroy' => ['DELETE', "/api/sessions/{$s}/screens/{$b['screen']->id}", [], 404],
        'api.clients.screens' => ['GET', "/api/clients/{$b['client']->id}/screens", [], 404],
        'api.sessions.pack' => ['GET', "/api/sessions/{$s}/pack?format=html", [], 404],
        // Your account (v1.5): only the device's own person, never workspace B. Signing out everywhere ends this
        // device's token too, so it comes last.
        'api.account' => ['GET', '/api/account', [], 'list'],
        // Since 2.0.0-a: "Switch back to classic" changes only the calling device's own person (no id in the request);
        // the answer is that person's flags. The test checks afterwards that Bob's setting did not move.
        'api.account.ui' => ['PUT', '/api/account/ui', ['classic' => true], fn (TestResponse $r): bool => $r->status() === 200 && $r->json('ui_classic') === true],
        // Since 2.0.0-g: the calling person's own design switch.
        'api.account.ui.show' => ['GET', '/api/account/ui', [], 'list'],
        // Entitlements (v1.6): only the device's own person in its own workspace, never B's numbers or names.
        'api.me.entitlements' => ['GET', '/api/me/entitlements', [], 'list'],
        // A person's own key: only ever their own (no id in the request); a wrong key says so and changes nothing of B.
        'api.me.transcription-key.put' => ['PUT', '/api/me/transcription-key', ['provider' => 'groq', 'key' => 'short'],
            fn (TestResponse $r): bool => $r->status() === 422 && $r->json('code') === 'invalid_key'],
        'api.me.transcription-key.delete' => ['DELETE', '/api/me/transcription-key', [], fn (TestResponse $r): bool => $r->status() === 204],
        // B's upgrade request is never A's: A has none, whatever B asked for.
        'api.upgrade-requests.latest' => ['GET', '/api/upgrade-requests/latest', [], 404],
        // Since v1.6.2: another workspace's code is no different from an unknown one.
        'api.promo-codes.check' => ['POST', '/api/promo-codes/check', ['code' => 'NO-SUCH-CODE'], fn (TestResponse $r): bool => $r->status() === 422 && $r->json('code') === 'invalid_promo_code'],
        'api.upgrade-requests.store' => ['POST', '/api/upgrade-requests', ['plan' => 'no-such-plan'], fn (TestResponse $r): bool => $r->status() === 422],
        // Since v1.7: Bob's request and receipt are the same 404 as unknown ones.
        'api.upgrade-requests.proofs.show' => ['GET', "/api/upgrade-requests/{$b['upgrade']->id}/proofs/{$b['proof']->id}", [], 404],
        'api.upgrade-requests.proofs.store' => ['POST', "/api/upgrade-requests/{$b['upgrade']->id}/proofs", [], 404],
        'api.upgrade-requests.proofs.destroy' => ['DELETE', "/api/upgrade-requests/{$b['upgrade']->id}/proofs/{$b['proof']->id}", [], 404],
        'api.upgrade-requests.paid' => ['POST', "/api/upgrade-requests/{$b['upgrade']->id}/paid", ['payment_reference' => 'HIJACK'], 404],
        'api.upgrade-requests.cancel' => ['POST', "/api/upgrade-requests/{$b['upgrade']->id}/cancel", [], 404],
        'api.account.destroy' => ['DELETE', '/api/account', [], fn (TestResponse $r): bool => $r->status() === 422 && $r->json('code') === 'validation_failed'],
        'api.account.export' => ['POST', '/api/account/export', [], fn (TestResponse $r): bool => $r->status() === 202],
        'api.account.export.show' => ['GET', '/api/account/export/'.ISO_MISSING_EXPORT, [], 404],
        'api.account.sign-out-everywhere' => ['POST', '/api/account/sign-out-everywhere', [], fn (TestResponse $r): bool => $r->status() === 204],
        // Since v1.8 P6.3: inviting people; another workspace's invitation and link are the same 404 as unknown ids.
        'api.workspace.invitations' => ['GET', '/api/workspace/invitations', [], 'list'],
        'api.workspace.invitations.store' => ['POST', '/api/workspace/invitations', ['email' => 'iso-new@example.test', 'role' => 'member'], fn (TestResponse $r): bool => in_array($r->status(), [201, 403], true)],
        'api.workspace.invitations.destroy' => ['DELETE', "/api/workspace/invitations/{$b['bobInvitation']->id}", [], 404],
        'api.workspace.invite-links.store' => ['POST', '/api/workspace/invite-links', ['role' => 'viewer'], fn (TestResponse $r): bool => in_array($r->status(), [201, 403], true)],
        'api.workspace.invite-links.destroy' => ['DELETE', "/api/workspace/invite-links/{$b['bobLink']->id}", [], 404],
        // Since v1.8 P6.4: the guide and the release notes are the server's, the same for everyone; support writes A's own.
        'api.help.guides' => ['GET', '/api/help/guides?q=call', [], 'list'],
        'api.help.guides.show' => ['GET', '/api/help/guides/after-a-call', [], 'list'],
        'api.help.whats-new' => ['GET', '/api/help/whats-new', [], 'list'],
        'api.support.messages' => ['POST', '/api/support/messages', ['subject' => 'Hi', 'message' => 'A question', 'include_device_report' => true], fn (TestResponse $r): bool => $r->status() === 202],
        // Since v1.9 (L1): Live Assist. Lists hold only Alice's own; Bob's prep and session are the same 404 as unknown ids.
        'api.assist.preps' => ['GET', '/api/assist/preps', [], 'list'],
        'api.assist.preps.store' => ['POST', '/api/assist/preps', ['meeting_type' => 'general'], fn (TestResponse $r): bool => $r->status() === 201],
        'api.assist.preps.show' => ['GET', "/api/assist/preps/{$b['assistPrep']->id}", [], 404],
        'api.assist.preps.update' => ['PATCH', "/api/assist/preps/{$b['assistPrep']->id}", ['title' => 'HIJACK'], 404],
        'api.assist.preps.destroy' => ['DELETE', "/api/assist/preps/{$b['assistPrep']->id}", [], 404],
        'api.assist.preps.documents' => ['POST', "/api/assist/preps/{$b['assistPrep']->id}/documents", ['file_ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']], 404],
        'api.assist.preps.documents.destroy' => ['DELETE', "/api/assist/preps/{$b['assistPrep']->id}/documents/01ARZ3NDEKTSV4RRFFQ69G5FAV", [], 404],
        'api.assist.preps.arm' => ['POST', "/api/assist/preps/{$b['assistPrep']->id}/arm", [], 404],
        'api.assist.sessions' => ['GET', '/api/assist/sessions', [], 'list'],
        'api.assist.sessions.store' => ['POST', '/api/assist/sessions', ['platform' => 'desktop', 'prep_id' => $b['assistPrep']->id], 404],
        'api.assist.sessions.show' => ['GET', "/api/assist/sessions/{$b['assistSession']->id}", [], 404],
        'api.assist.sessions.report' => ['GET', "/api/assist/sessions/{$b['assistSession']->id}/report", [], 404],
        'api.assist.sessions.token' => ['POST', "/api/assist/sessions/{$b['assistSession']->id}/token", [], 404],
        'api.assist.sessions.turns' => ['POST', "/api/assist/sessions/{$b['assistSession']->id}/turns", ['turns' => [['client_ref' => 'x', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'HIJACK']]], 404],
        'api.assist.sessions.end' => ['POST', "/api/assist/sessions/{$b['assistSession']->id}/end", [], 404],
        'api.assist.sessions.destroy' => ['DELETE', "/api/assist/sessions/{$b['assistSession']->id}", [], 404],
        'api.assist.sessions.suggestions.action' => ['POST', "/api/assist/sessions/{$b['assistSession']->id}/suggestions/s1/action", ['action' => 'used'], 404],
        'api.assist.sessions.ask' => ['POST', "/api/assist/sessions/{$b['assistSession']->id}/ask", ['question' => 'What did they say?'], 404],
        'api.assist.sessions.live' => ['POST', "/api/assist/sessions/{$b['assistSession']->id}/live", ['type' => 'turn_final', 'turn_id' => 'x', 'channel' => 'other', 'text' => 'HIJACK'], 404],
    ];
}

function isoLeaks(string $body, array $b): array
{
    return array_values(array_filter([...ISO_SECRETS, ...isoIds($b)], fn (string $needle): bool => str_contains($body, $needle)));
}

/** @return list<RouteDefinition> */
function isoRoutes(string $prefix): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RouteDefinition $r): bool => str_starts_with($r->uri(), $prefix)));
}

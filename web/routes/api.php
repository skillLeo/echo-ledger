<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BinController;
use App\Http\Controllers\Api\ClientWorkspaceController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceReportController;
use App\Http\Controllers\Api\EntitlementsController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FilingRuleController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\LinkController;
use App\Http\Controllers\Api\LiveTranslateTokenController;
use App\Http\Controllers\Api\OrganiseController;
use App\Http\Controllers\Api\PackController;
use App\Http\Controllers\Api\RecordingController;
use App\Http\Controllers\Api\RequestLinkController;
use App\Http\Controllers\Api\ScreenController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\ShareController;
use App\Http\Controllers\Api\SttTokenController;
use App\Http\Controllers\Api\SttWebhookController;
use App\Http\Controllers\Api\TeammateController;
use App\Http\Controllers\Api\TranscriptEditController;
use App\Http\Controllers\Api\TranscriptViewsController;
use App\Http\Controllers\Api\UpgradeRequestController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\VocabularyController;
use App\Http\Middleware\EnsureCanRecord;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\TouchDevice;
use Illuminate\Support\Facades\Route;

/*
| EchoLedger REST API v1.1 — contract: docs/API.md.
| Every route except pairing, signed audio and provider webhooks requires a device bearer token + ability.
*/

Route::post('devices/register', [DeviceController::class, 'register'])
    ->middleware('throttle:pairing')->name('api.devices.register');

// Signing in with an account (v1.5): the email and password, or the browser sign-in's one-time code and PKCE verifier.
// Since v1.5 (Phase 1C): what the sign-in screen offers (password, Google, sign-up), public like the site pages.
Route::get('auth/options', [AuthController::class, 'options'])
    ->middleware('throttle:site')->name('api.auth.options');
Route::post('auth/password', [AuthController::class, 'password'])
    ->middleware('throttle:auth-password')->name('api.auth.password');
Route::post('auth/device/token', [AuthController::class, 'deviceToken'])
    ->middleware('throttle:auth-token')->name('api.auth.device.token');
// Since v1.6 (P1): the email first, then a 6-digit code by email; a new address then makes its account.
Route::post('auth/start', [AuthController::class, 'start'])
    ->middleware('throttle:auth-start')->name('api.auth.start');
Route::post('auth/code', [AuthController::class, 'code'])
    ->middleware('throttle:auth-code')->name('api.auth.code');
Route::post('auth/code/verify', [AuthController::class, 'verifyCode'])
    ->middleware('throttle:auth-code-verify')->name('api.auth.code.verify');
Route::post('auth/sign-up', [AuthController::class, 'signUp'])
    ->middleware('throttle:auth-sign-up')->name('api.auth.sign-up');

Route::post('webhooks/stt/{provider}', SttWebhookController::class)
    ->middleware('throttle:webhooks')->name('api.webhooks.stt');

Route::get('recordings/{recording}/audio', [RecordingController::class, 'audio'])
    ->middleware(['signed', 'throttle:device'])->name('api.recordings.audio');

// Since v1.6 a suspended account's devices are refused on every call (403 account_suspended).
Route::middleware(['auth:sanctum', EnsureNotSuspended::class, TouchDevice::class])->group(function (): void {
    // Every authenticated route except the upload routes counts against the general per-device limit.
    Route::middleware('throttle:device')->group(function (): void {
        Route::post('devices/rotate-token', [DeviceController::class, 'rotateToken'])->name('api.devices.rotate');
        Route::post('devices/report', DeviceReportController::class)->middleware(['abilities:config:read', 'throttle:device-report'])->name('api.devices.report');

        Route::get('config', [ConfigController::class, 'show'])->middleware('abilities:config:read')->name('api.config');
        Route::get('account', [AccountController::class, 'show'])->middleware('abilities:config:read')->name('api.account');
        // Entitlements (v1.6): the plan, features, limits and engines of this device's person; the apps show only this.
        Route::get('me/entitlements', [EntitlementsController::class, 'show'])->middleware('abilities:config:read')->name('api.me.entitlements');
        // Upgrade requests (v1.6): the newest one of this device's person.
        Route::get('upgrade-requests/latest', [UpgradeRequestController::class, 'latest'])->middleware('abilities:config:read')->name('api.upgrade-requests.latest');
        Route::get('security', [SecurityController::class, 'index'])->middleware('abilities:config:read')->name('api.security');
        Route::get('clients', [ConfigController::class, 'clients'])->middleware('abilities:library:read')->name('api.clients');

        Route::post('stt/token', SttTokenController::class)->middleware(['abilities:stt:token', 'throttle:stt-token', EnsureCanRecord::class])->name('api.stt.token');
        Route::post('live-translate/token', LiveTranslateTokenController::class)->middleware(['abilities:stt:token', 'throttle:stt-token', EnsureCanRecord::class])->name('api.live-translate.token');
        Route::post('usage', [UsageController::class, 'store'])->middleware(['abilities:stt:token', EnsureCanRecord::class])->name('api.usage.store');

        Route::middleware('abilities:sessions:write')->group(function (): void {
            // Since v1.5 a viewer's device records, uploads and changes nothing (403 not_allowed): `record` on recording
            // and upload routes, `ownContent` where a person makes or changes their own recordings and projects. Taking
            // back a link stays open to everyone, as it only narrows who sees a recording.
            $own = EnsureCanRecord::class.':ownContent';
            Route::post('sessions', [SessionController::class, 'store'])->middleware(EnsureCanRecord::class)->name('api.sessions.store');
            Route::post('sessions/{id}/finish', [SessionController::class, 'finish'])->name('api.sessions.finish');
            Route::post('sessions/{id}/recordings', [SessionController::class, 'addRecording'])->middleware(EnsureCanRecord::class)->name('api.sessions.recordings');
            Route::post('sessions/{id}/transcripts', [SessionController::class, 'storeTranscript'])->middleware(EnsureCanRecord::class)->name('api.sessions.transcripts');
            Route::delete('sessions/{id}', [SessionController::class, 'destroy'])->middleware($own)->name('api.sessions.destroy');
            Route::patch('sessions/{id}', [OrganiseController::class, 'update'])->middleware($own)->name('api.sessions.update');
            Route::post('sessions/bulk', [OrganiseController::class, 'bulk'])->middleware($own)->name('api.sessions.bulk');
            Route::post('clients', [OrganiseController::class, 'storeClient'])->middleware($own)->name('api.clients.store');
            Route::patch('clients/{id}', [OrganiseController::class, 'updateClient'])->middleware($own)->name('api.clients.update');
            // Projects (v1.5): deleting one sends its recordings to the Inbox and its subfolders up a level.
            Route::delete('clients/{id}', [OrganiseController::class, 'destroyClient'])->middleware($own)->name('api.clients.destroy');
            // Filing rules (v1.5): title, app and contact rules that file a recording ending without a project.
            Route::post('clients/{id}/rules', [FilingRuleController::class, 'store'])->middleware($own)->name('api.clients.rules.store');
            Route::delete('clients/{id}/rules/{rule}', [FilingRuleController::class, 'destroy'])->middleware($own)->name('api.clients.rules.destroy');
            // Client workspaces (v1.4): brief, memory, files, notes and Ask.
            Route::put('clients/{id}/brief', [ClientWorkspaceController::class, 'updateBrief'])->middleware($own)->name('api.clients.brief.update');
            Route::post('clients/{id}/brief/refresh', [ClientWorkspaceController::class, 'refreshBrief'])->middleware($own)->name('api.clients.brief.refresh');
            Route::post('clients/{id}/memory', [ClientWorkspaceController::class, 'storeMemory'])->middleware($own)->name('api.clients.memory.store');
            Route::patch('clients/{id}/memory/{item}', [ClientWorkspaceController::class, 'updateMemory'])->middleware($own)->name('api.clients.memory.update');
            Route::delete('clients/{id}/memory/{item}', [ClientWorkspaceController::class, 'destroyMemory'])->middleware($own)->name('api.clients.memory.destroy');
            Route::post('clients/{id}/files', [ClientWorkspaceController::class, 'storeFile'])->middleware($own)->name('api.clients.files.store');
            Route::delete('clients/{id}/files/{file}', [ClientWorkspaceController::class, 'destroyFile'])->middleware($own)->name('api.clients.files.destroy');
            Route::post('clients/{id}/ask', [ClientWorkspaceController::class, 'ask'])->middleware([$own, 'throttle:ask'])->name('api.clients.ask');
            Route::post('sessions/{id}/notes', [ClientWorkspaceController::class, 'makeNotes'])->middleware($own)->name('api.sessions.notes.make');
            Route::post('transcripts/{id}/suggestions/{suggestion}', [TranscriptEditController::class, 'suggestion'])->middleware($own)->name('api.transcripts.suggestions');
            Route::delete('devices/{id}', [SecurityController::class, 'revokeDevice'])->name('api.devices.revoke');
            // Your account (v1.5): sign out every device and console session; delete the account after 7 days.
            // Your own transcription key (v1.6, the Free plan): checked with Groq once, kept encrypted, never sent back.
            Route::put('me/transcription-key', [EntitlementsController::class, 'putKey'])->middleware('throttle:transcription-key')->name('api.me.transcription-key.put');
            Route::delete('me/transcription-key', [EntitlementsController::class, 'deleteKey'])->name('api.me.transcription-key.delete');
            // Upgrade requests (v1.6): ask for a plan, one waiting at a time.
            Route::post('upgrade-requests', [UpgradeRequestController::class, 'store'])->middleware('throttle:upgrade-request')->name('api.upgrade-requests.store');
            Route::post('account/sign-out-everywhere', [AccountController::class, 'signOutEverywhere'])->name('api.account.sign-out-everywhere');
            Route::delete('account', [AccountController::class, 'destroy'])->name('api.account.destroy');
            Route::post('sessions/{id}/restore', [BinController::class, 'restore'])->middleware($own)->name('api.sessions.restore');
            Route::delete('bin/{id}', [BinController::class, 'destroy'])->middleware($own)->name('api.bin.destroy');
            Route::post('sessions/{id}/markers', [SessionController::class, 'storeMarker'])->middleware($own)->name('api.sessions.markers.store');
            Route::post('sessions/{id}/retranscribe', [SessionController::class, 'retranscribe'])->middleware($own)->name('api.sessions.retranscribe');
            Route::delete('sessions/{id}/markers/{marker}', [SessionController::class, 'destroyMarker'])->middleware($own)->name('api.sessions.markers.destroy');
            // Screen moments (v1.5): a screen comes off the recording's timeline (sending one is an upload route below).
            Route::delete('sessions/{id}/screens/{screen}', [ScreenController::class, 'destroy'])->middleware($own)->name('api.sessions.screens.destroy');
            Route::put('sessions/{id}/speakers', [TranscriptEditController::class, 'speakers'])->middleware($own)->name('api.sessions.speakers');
            Route::post('sessions/{id}/shares', [ShareController::class, 'store'])->middleware($own)->name('api.sessions.shares.store');
            Route::delete('shares/{id}', [ShareController::class, 'destroy'])->name('api.shares.destroy');
            // Share links for a project (v1.5): its recordings behind one link.
            Route::post('clients/{id}/shares', [ShareController::class, 'storeForProject'])->middleware($own)->name('api.clients.shares.store');
            // Sending to a teammate (v1.5): a recording or project to accounts on this server; save a copy of one sent to you.
            Route::post('sessions/{id}/send', [TeammateController::class, 'sendSession'])->middleware([$own, 'throttle:send'])->name('api.sessions.send');
            Route::post('clients/{id}/send', [TeammateController::class, 'sendProject'])->middleware([$own, 'throttle:send'])->name('api.clients.send');
            // Stop sharing stays open to everyone, like taking back a link: it only narrows who sees a recording.
            Route::delete('sent/{id}', [TeammateController::class, 'stopSharing'])->name('api.sent.destroy');
            Route::post('shared-with-me/{id}/save', [TeammateController::class, 'save'])->middleware(EnsureCanRecord::class.':saveShared')->name('api.shared.save');
            Route::delete('shared-with-me/{id}', [TeammateController::class, 'destroy'])->name('api.shared.destroy');
            // Request links (v1.5): a public page others send files through.
            Route::post('request-links', [RequestLinkController::class, 'store'])->middleware(EnsureCanRecord::class)->name('api.request-links.store');
            Route::delete('request-links/{id}', [RequestLinkController::class, 'destroy'])->name('api.request-links.destroy');
            Route::post('vocabulary/keyterms', [VocabularyController::class, 'addKeyterm'])->middleware($own)->name('api.vocabulary.keyterms');
            Route::post('vocabulary/replacements', [VocabularyController::class, 'addReplacement'])->middleware($own)->name('api.vocabulary.replacements');
            Route::patch('transcripts/{id}', [TranscriptEditController::class, 'update'])->middleware($own)->name('api.transcripts.update');
            Route::post('transcripts/{id}/replace', [TranscriptEditController::class, 'replace'])->middleware($own)->name('api.transcripts.replace');
            Route::post('transcripts/{id}/views', [TranscriptViewsController::class, 'store'])->middleware($own)->name('api.transcripts.views.make');
            Route::post('transcripts/{id}/restore', [TranscriptEditController::class, 'restore'])->middleware($own)->name('api.transcripts.restore');
        });

        Route::middleware('abilities:library:read')->group(function (): void {
            Route::get('library', [LibraryController::class, 'index'])->name('api.library');
            Route::get('library/views', [OrganiseController::class, 'views'])->name('api.library.views');
            Route::get('bin', [BinController::class, 'index'])->name('api.bin');
            Route::get('storage', [BinController::class, 'storage'])->name('api.storage');
            Route::get('activity', [ActivityController::class, 'index'])->name('api.activity');
            Route::post('activity/read', [ActivityController::class, 'read'])->name('api.activity.read');
            Route::post('sessions/export', [OrganiseController::class, 'export'])->name('api.sessions.export');
            Route::get('search', [SearchController::class, 'index'])->middleware('throttle:search')->name('api.search');
            Route::get('sessions/{id}', [SessionController::class, 'show'])->name('api.sessions.show');
            Route::post('sessions/{id}/opened', [SessionController::class, 'opened'])->name('api.sessions.opened');
            Route::get('transcripts/{id}', [LibraryController::class, 'transcript'])->name('api.transcripts.show');
            Route::get('transcripts/{id}/revisions', [TranscriptEditController::class, 'revisions'])->name('api.transcripts.revisions');
            Route::get('transcripts/{id}/export', [ExportController::class, 'show'])->name('api.transcripts.export');
            Route::get('transcripts/{id}/views', [TranscriptViewsController::class, 'show'])->name('api.transcripts.views');
            Route::get('sessions/{id}/shares', [ShareController::class, 'index'])->name('api.sessions.shares');
            Route::get('shares', [ShareController::class, 'all'])->name('api.shares');
            Route::get('sent', [TeammateController::class, 'sent'])->name('api.sent');
            Route::get('shared-with-me', [TeammateController::class, 'index'])->name('api.shared');
            Route::get('request-links', [RequestLinkController::class, 'index'])->name('api.request-links');
            // Links in the apps (v1.5): what a /s/, /p/ or /r/ link is for this device, to open it in place.
            Route::get('links/resolve', [LinkController::class, 'resolve'])->name('api.links.resolve');
            Route::get('vocabulary', [VocabularyController::class, 'show'])->name('api.vocabulary');
            Route::get('clients/{id}/workspace', [ClientWorkspaceController::class, 'show'])->name('api.clients.workspace');
            Route::get('clients/{id}/bundle', [ClientWorkspaceController::class, 'bundle'])->name('api.clients.bundle');
            Route::get('clients/{id}/rules', [FilingRuleController::class, 'index'])->name('api.clients.rules');
            Route::get('sessions/{id}/notes', [ClientWorkspaceController::class, 'notes'])->name('api.sessions.notes');
            // Screen moments (v1.5): a recording's screens in time order, and a project's Screens tab.
            Route::get('sessions/{id}/screens', [ScreenController::class, 'index'])->name('api.sessions.screens');
            Route::get('clients/{id}/screens', [ScreenController::class, 'project'])->name('api.clients.screens');
            // Meeting pack (v1.5): the transcript with its screens, a page to print or a ZIP.
            Route::get('sessions/{id}/pack', [PackController::class, 'show'])->name('api.sessions.pack');
            // Your account (v1.5): "Export my data", one ZIP made in the background.
            Route::post('account/export', [AccountController::class, 'export'])->name('api.account.export');
            Route::get('account/export/{id}', [AccountController::class, 'exportStatus'])->name('api.account.export.show');
        });
    });

    // Screen moments (v1.5): pictures sent during a meeting count against the upload limit, not the general one.
    Route::post('sessions/{id}/screens', [ScreenController::class, 'store'])
        ->middleware(['abilities:sessions:write', 'throttle:uploads', EnsureCanRecord::class])->name('api.sessions.screens.store');

    // Upload routes have their own, larger limit (a stricter limiter in front would make it unreachable).
    Route::middleware(['abilities:recordings:upload', 'throttle:uploads'])->group(function (): void {
        Route::get('recordings/{id}', [RecordingController::class, 'show'])->name('api.recordings.show');
        Route::post('recordings/{id}/chunks', [RecordingController::class, 'chunk'])->middleware(EnsureCanRecord::class)->name('api.recordings.chunks');
        Route::post('recordings/{id}/complete', [RecordingController::class, 'complete'])->middleware(EnsureCanRecord::class)->name('api.recordings.complete');
    });
});

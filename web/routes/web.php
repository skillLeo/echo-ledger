<?php

declare(strict_types=1);

use App\Http\Controllers\AccountPageController;
use App\Http\Controllers\AdminExportController;
use App\Http\Controllers\AdminMediaController;
use App\Http\Controllers\AdminPackController;
use App\Http\Controllers\AppLinksController;
use App\Http\Controllers\Auth\DeviceSignInController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\ConsoleUploadController;
use App\Http\Controllers\DownloadsController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\PasskeyLoginController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\RequestLinkPageController;
use App\Http\Controllers\ScreenMediaController;
use App\Http\Controllers\SiteController;
use App\Http\Middleware\EnsureWorkspaceMember;
use Illuminate\Support\Facades\Route;

// Console routes outside Filament's pages need a confirmed email too (since v1.5), like the panel's own pages.
$consoleVerified = 'verified:filament.admin.auth.email-verification.prompt';

// The public site (since v1.5): the landing page, the downloads page, the legal pages and the apps' update manifests (docs/API.md
// "Public pages and app downloads"). Nothing here shows anyone's data; throttled per IP.
Route::middleware('throttle:site')->group(function (): void {
    Route::get('/', [SiteController::class, 'home'])->name('site.home');
    Route::get('/downloads', [DownloadsController::class, 'page'])->name('downloads');
    // The legal pages (drafts for review), linked from the site, the console and the apps.
    Route::get('/terms', [LegalController::class, 'terms'])->name('legal.terms');
    Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
    Route::get('/downloads/android.json', [DownloadsController::class, 'android'])->name('downloads.android');
    Route::get('/downloads/desktop.json', [DownloadsController::class, 'desktop'])->name('downloads.desktop');
    Route::get('/downloads/files/{name}', [DownloadsController::class, 'file'])
        ->where('name', '[A-Za-z0-9][A-Za-z0-9._-]{0,119}')
        ->name('downloads.file');
});

// Android App Links (v1.5): the phone app opens this server's /s/, /p/ and /r/ links itself when it is installed.
Route::get('/.well-known/assetlinks.json', AppLinksController::class)->name('app-links');

// Decrypted playback for the admin transcript player (HTTP Range supported).
Route::get('/admin-media/recordings/{recording}', AdminMediaController::class)
    ->middleware(['auth:web', $consoleVerified, EnsureWorkspaceMember::class])
    ->name('admin.recordings.stream');

// Screen moments (v1.5): a picture or thumbnail from the signed link the API hands out (30 minutes, no token), and on
// the console for the workspace's own recordings. The signed link is fetched by apps that keep no cookies, so like the
// API's signed audio it runs without the web group: no session is started or saved and no cookie is set.
Route::get('/screen-media/{screen}/{variant}', [ScreenMediaController::class, 'signed'])
    ->whereUlid('screen')->whereIn('variant', ['image', 'thumb'])
    ->withoutMiddleware('web')
    ->middleware(['signed', 'throttle:uploads'])->name('screens.media');
Route::get('/admin-media/screens/{screen}/{variant}', [ScreenMediaController::class, 'console'])
    ->whereUlid('screen')->whereIn('variant', ['image', 'thumb'])
    ->middleware(['auth:web', $consoleVerified, EnsureWorkspaceMember::class])->name('admin.screens.media');

// Console exports of a transcript (download; `html` opens for printing to PDF).
Route::get('/admin-export/transcripts/{transcript}/{format}', AdminExportController::class)
    ->middleware(['auth:web', $consoleVerified, EnsureWorkspaceMember::class])
    ->name('admin.transcripts.export');

// Export → Meeting pack (v1.5): the transcript with its screens; `html` opens with a Print button, `zip` downloads.
Route::get('/admin-export/sessions/{session}/pack/{format}', AdminPackController::class)
    ->whereUlid('session')->whereIn('format', ['html', 'zip'])
    ->middleware(['auth:web', $consoleVerified, EnsureWorkspaceMember::class])
    ->name('admin.sessions.pack');

// Library → Upload: the browser's chunked, resumable uploads (the devices' chunk path, behind the console session).
Route::middleware(['auth:web', $consoleVerified, EnsureWorkspaceMember::class, 'throttle:uploads'])
    ->prefix('/admin-upload/recordings')->name('admin.uploads.')->group(function (): void {
        Route::post('/', [ConsoleUploadController::class, 'store'])->name('store');
        Route::get('/{recording}', [ConsoleUploadController::class, 'show'])->name('show');
        Route::post('/{recording}/chunks/{index}', [ConsoleUploadController::class, 'chunk'])->whereNumber('index')->name('chunk');
        Route::post('/{recording}/complete', [ConsoleUploadController::class, 'complete'])->name('complete');
        Route::delete('/{recording}', [ConsoleUploadController::class, 'destroy'])->name('destroy');
    });

// Share links (view-only): the token is the only key, so every route is throttled per IP.
Route::middleware('throttle:share')->group(function (): void {
    Route::get('/s/{token}', [PublicShareController::class, 'show'])->name('share.show');
    Route::post('/s/{token}', [PublicShareController::class, 'unlock'])->middleware('throttle:share-password')->name('share.unlock');
    Route::get('/s/{token}/audio', [PublicShareController::class, 'audio'])->name('share.audio');
    Route::get('/s/{token}/download', [PublicShareController::class, 'download'])->name('share.download');
    // A project's link (v1.5): its recordings, each opening as /s/ would.
    Route::get('/p/{token}', [PublicShareController::class, 'project'])->name('share.project');
    Route::post('/p/{token}', [PublicShareController::class, 'unlockProject'])->middleware('throttle:share-password')->name('share.project.unlock');
    Route::get('/p/{token}/{session}', [PublicShareController::class, 'projectSession'])->whereUlid('session')->name('share.project.session');
    Route::get('/p/{token}/{session}/audio', [PublicShareController::class, 'projectAudio'])->whereUlid('session')->name('share.project.audio');
    Route::get('/p/{token}/{session}/download', [PublicShareController::class, 'projectDownload'])->whereUlid('session')->name('share.project.download');
});

// Request links (v1.5): a public page where anyone with the link sends files (no account), chunked like Upload.
Route::get('/r/{token}', [RequestLinkPageController::class, 'show'])->middleware('throttle:share')->name('request.show');
Route::middleware('throttle:request-upload')->prefix('/r/{token}/files')->name('request.files.')->group(function (): void {
    Route::post('/', [RequestLinkPageController::class, 'store'])->name('store');
    Route::post('/{upload}/chunks/{index}', [RequestLinkPageController::class, 'chunk'])->whereNumber('index')->name('chunk');
    Route::post('/{upload}/complete', [RequestLinkPageController::class, 'complete'])->name('complete');
});

// Sign in with Google (v1.5): Socialite's redirect and callback, then "confirm it is you" when Google's address matches
// an account not linked yet, or the account has two-step sign-in. Throttled per IP.
Route::middleware('throttle:google')->prefix('/auth/google')->name('auth.google.')->group(function (): void {
    Route::get('/redirect', [GoogleController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [GoogleController::class, 'callback'])->name('callback');
    Route::get('/confirm', [GoogleController::class, 'confirmForm'])->name('confirm');
    Route::post('/confirm', [GoogleController::class, 'confirm'])->name('confirm.store');
});

// Your account (v1.5): /account/delete without the app (Google Play links to it), and the "Export my data" link.
Route::middleware('throttle:account')->group(function (): void {
    Route::get('/account/delete', [AccountPageController::class, 'deletePage'])->name('account.delete');
    Route::post('/account/delete', [AccountPageController::class, 'delete'])->middleware('auth:web')->name('account.delete.store');
});
// Signed for its 24 hours and fetched without a session, as the apps download it too.
Route::get('/account/export/{export}/download', [AccountPageController::class, 'download'])
    ->whereUlid('export')->withoutMiddleware('web')->middleware(['signed', 'throttle:account'])->name('account.export.download');

// The apps' browser sign-in (v1.5, docs/API.md "Signing in with an account"): PKCE S256, the approval page behind the
// console's sign-in, then the app's echoledger://signed-in link with a one-time code.
Route::middleware('throttle:device-sign-in')->group(function (): void {
    Route::get('/device/sign-in', [DeviceSignInController::class, 'show'])->name('device.sign-in');
    Route::post('/device/sign-in', [DeviceSignInController::class, 'approve'])->middleware('auth:web')->name('device.sign-in.approve');
});

// Passkey sign-in on the console login page (B16): a challenge, then the signed answer. Throttled per IP.
Route::middleware(['guest:web', 'throttle:passkey'])->group(function (): void {
    Route::post('/passkeys/login/options', [PasskeyLoginController::class, 'options'])->name('passkeys.login.options');
    Route::post('/passkeys/login', [PasskeyLoginController::class, 'login'])->name('passkeys.login');
});

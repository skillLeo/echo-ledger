<?php

declare(strict_types=1);

use App\Http\Controllers\AdminExportController;
use App\Http\Controllers\AdminMediaController;
use App\Http\Controllers\ConsoleUploadController;
use App\Http\Controllers\DownloadsController;
use App\Http\Controllers\PasskeyLoginController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\SiteController;
use App\Http\Middleware\EnsureWorkspaceMember;
use Illuminate\Support\Facades\Route;

// The public site (since v1.5): the landing page, the downloads page and the apps' update manifests (docs/API.md
// "Public pages and app downloads"). Nothing here shows anyone's data; throttled per IP.
Route::middleware('throttle:site')->group(function (): void {
    Route::get('/', [SiteController::class, 'home'])->name('site.home');
    Route::get('/downloads', [DownloadsController::class, 'page'])->name('downloads');
    Route::get('/downloads/android.json', [DownloadsController::class, 'android'])->name('downloads.android');
    Route::get('/downloads/desktop.json', [DownloadsController::class, 'desktop'])->name('downloads.desktop');
    Route::get('/downloads/files/{name}', [DownloadsController::class, 'file'])
        ->where('name', '[A-Za-z0-9][A-Za-z0-9._-]{0,119}')
        ->name('downloads.file');
});

// Decrypted playback for the admin transcript player (HTTP Range supported).
Route::get('/admin-media/recordings/{recording}', AdminMediaController::class)
    ->middleware(['auth:web', EnsureWorkspaceMember::class])
    ->name('admin.recordings.stream');

// Console exports of a transcript (download; `html` opens for printing to PDF).
Route::get('/admin-export/transcripts/{transcript}/{format}', AdminExportController::class)
    ->middleware(['auth:web', EnsureWorkspaceMember::class])
    ->name('admin.transcripts.export');

// Library → Upload: the browser's chunked, resumable uploads (the devices' chunk path, behind the console session).
Route::middleware(['auth:web', EnsureWorkspaceMember::class, 'throttle:uploads'])
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
});

// Passkey sign-in on the console login page (B16): a challenge, then the signed answer. Throttled per IP.
Route::middleware(['guest:web', 'throttle:passkey'])->group(function (): void {
    Route::post('/passkeys/login/options', [PasskeyLoginController::class, 'options'])->name('passkeys.login.options');
    Route::post('/passkeys/login', [PasskeyLoginController::class, 'login'])->name('passkeys.login');
});

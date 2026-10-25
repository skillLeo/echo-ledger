<?php

declare(strict_types=1);

use App\Http\Controllers\AdminExportController;
use App\Http\Controllers\AdminMediaController;
use App\Http\Controllers\PublicShareController;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Decrypted playback for the admin transcript player (HTTP Range supported).
Route::get('/admin-media/recordings/{recording}', AdminMediaController::class)
    ->middleware(['auth:web', EnsureSuperAdmin::class])
    ->name('admin.recordings.stream');

// Console exports of a transcript (download; `html` opens for printing to PDF).
Route::get('/admin-export/transcripts/{transcript}/{format}', AdminExportController::class)
    ->middleware(['auth:web', EnsureSuperAdmin::class])
    ->name('admin.transcripts.export');

// Share links (view-only): the token is the only key, so every route is throttled per IP.
Route::middleware('throttle:share')->group(function (): void {
    Route::get('/s/{token}', [PublicShareController::class, 'show'])->name('share.show');
    Route::post('/s/{token}', [PublicShareController::class, 'unlock'])->middleware('throttle:share-password')->name('share.unlock');
    Route::get('/s/{token}/audio', [PublicShareController::class, 'audio'])->name('share.audio');
});

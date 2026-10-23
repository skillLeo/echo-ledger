<?php

declare(strict_types=1);

use App\Http\Controllers\AdminMediaController;
use App\Http\Middleware\EnsureAdminMfa;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Decrypted playback for the admin transcript player (HTTP Range supported).
Route::get('/admin-media/recordings/{recording}', AdminMediaController::class)
    ->middleware(['auth:web', EnsureAdminMfa::class])
    ->name('admin.recordings.stream');

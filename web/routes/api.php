<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\BinController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\OrganiseController;
use App\Http\Controllers\Api\RecordingController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\ShareController;
use App\Http\Controllers\Api\SttTokenController;
use App\Http\Controllers\Api\SttWebhookController;
use App\Http\Controllers\Api\TranscriptEditController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\VocabularyController;
use App\Http\Middleware\TouchDevice;
use Illuminate\Support\Facades\Route;

/*
| EchoLedger REST API v1.1 — contract: docs/API.md.
| Every route except pairing, signed audio and provider webhooks requires a device bearer token + ability.
*/

Route::post('devices/register', [DeviceController::class, 'register'])
    ->middleware('throttle:pairing')->name('api.devices.register');

Route::post('webhooks/stt/{provider}', SttWebhookController::class)
    ->middleware('throttle:webhooks')->name('api.webhooks.stt');

Route::get('recordings/{recording}/audio', [RecordingController::class, 'audio'])
    ->middleware(['signed', 'throttle:device'])->name('api.recordings.audio');

Route::middleware(['auth:sanctum', TouchDevice::class])->group(function (): void {
    // Every authenticated route except the upload routes counts against the general per-device limit.
    Route::middleware('throttle:device')->group(function (): void {
        Route::post('devices/rotate-token', [DeviceController::class, 'rotateToken'])->name('api.devices.rotate');

        Route::get('config', [ConfigController::class, 'show'])->middleware('abilities:config:read')->name('api.config');
        Route::get('security', [SecurityController::class, 'index'])->middleware('abilities:config:read')->name('api.security');
        Route::get('clients', [ConfigController::class, 'clients'])->middleware('abilities:library:read')->name('api.clients');

        Route::post('stt/token', SttTokenController::class)->middleware(['abilities:stt:token', 'throttle:stt-token'])->name('api.stt.token');
        Route::post('usage', [UsageController::class, 'store'])->middleware('abilities:stt:token')->name('api.usage.store');

        Route::middleware('abilities:sessions:write')->group(function (): void {
            Route::post('sessions', [SessionController::class, 'store'])->name('api.sessions.store');
            Route::post('sessions/{id}/finish', [SessionController::class, 'finish'])->name('api.sessions.finish');
            Route::post('sessions/{id}/recordings', [SessionController::class, 'addRecording'])->name('api.sessions.recordings');
            Route::post('sessions/{id}/transcripts', [SessionController::class, 'storeTranscript'])->name('api.sessions.transcripts');
            Route::delete('sessions/{id}', [SessionController::class, 'destroy'])->name('api.sessions.destroy');
            Route::patch('sessions/{id}', [OrganiseController::class, 'update'])->name('api.sessions.update');
            Route::post('sessions/bulk', [OrganiseController::class, 'bulk'])->name('api.sessions.bulk');
            Route::post('clients', [OrganiseController::class, 'storeClient'])->name('api.clients.store');
            Route::patch('clients/{id}', [OrganiseController::class, 'updateClient'])->name('api.clients.update');
            Route::delete('devices/{id}', [SecurityController::class, 'revokeDevice'])->name('api.devices.revoke');
            Route::post('sessions/{id}/restore', [BinController::class, 'restore'])->name('api.sessions.restore');
            Route::delete('bin/{id}', [BinController::class, 'destroy'])->name('api.bin.destroy');
            Route::post('sessions/{id}/markers', [SessionController::class, 'storeMarker'])->name('api.sessions.markers.store');
            Route::post('sessions/{id}/retranscribe', [SessionController::class, 'retranscribe'])->name('api.sessions.retranscribe');
            Route::delete('sessions/{id}/markers/{marker}', [SessionController::class, 'destroyMarker'])->name('api.sessions.markers.destroy');
            Route::put('sessions/{id}/speakers', [TranscriptEditController::class, 'speakers'])->name('api.sessions.speakers');
            Route::post('sessions/{id}/shares', [ShareController::class, 'store'])->name('api.sessions.shares.store');
            Route::delete('shares/{id}', [ShareController::class, 'destroy'])->name('api.shares.destroy');
            Route::post('vocabulary/keyterms', [VocabularyController::class, 'addKeyterm'])->name('api.vocabulary.keyterms');
            Route::post('vocabulary/replacements', [VocabularyController::class, 'addReplacement'])->name('api.vocabulary.replacements');
            Route::patch('transcripts/{id}', [TranscriptEditController::class, 'update'])->name('api.transcripts.update');
            Route::post('transcripts/{id}/replace', [TranscriptEditController::class, 'replace'])->name('api.transcripts.replace');
            Route::post('transcripts/{id}/restore', [TranscriptEditController::class, 'restore'])->name('api.transcripts.restore');
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
            Route::get('transcripts/{id}', [LibraryController::class, 'transcript'])->name('api.transcripts.show');
            Route::get('transcripts/{id}/revisions', [TranscriptEditController::class, 'revisions'])->name('api.transcripts.revisions');
            Route::get('transcripts/{id}/export', [ExportController::class, 'show'])->name('api.transcripts.export');
            Route::get('sessions/{id}/shares', [ShareController::class, 'index'])->name('api.sessions.shares');
            Route::get('vocabulary', [VocabularyController::class, 'show'])->name('api.vocabulary');
        });
    });

    // Upload routes have their own, larger limit (a stricter limiter in front would make it unreachable).
    Route::middleware(['abilities:recordings:upload', 'throttle:uploads'])->group(function (): void {
        Route::get('recordings/{id}', [RecordingController::class, 'show'])->name('api.recordings.show');
        Route::post('recordings/{id}/chunks', [RecordingController::class, 'chunk'])->name('api.recordings.chunks');
        Route::post('recordings/{id}/complete', [RecordingController::class, 'complete'])->name('api.recordings.complete');
    });
});

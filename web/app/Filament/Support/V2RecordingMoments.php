<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\CaptureSession;
use App\Models\Screen;
use App\Services\Screens\ScreenAssets;
use App\Services\Screens\ScreenStore;
use App\Services\TranscriptExporter;

/**
 * The v2.0 recording page's Moments (ui_v2.library), as the desktop app's viewer: each screen of the recording in time
 * order, its label ("Screen 3 at 14:32"), its description, the file name the meeting folder gives it (v1.8 P4.2) and
 * what was said within 15 seconds of it.
 */
final class V2RecordingMoments
{
    /**
     * @return list<array{id: string, number: int, label: string, at: string, at_ms: int, description: string|null, file_name: string,
     *     said: string|null, thumb: string, image: string}>
     */
    public static function for(CaptureSession $session): array
    {
        $screens = ScreenStore::ordered($session->id)->get();
        if ($screens->isEmpty()) {
            return [];
        }
        $lines = ScreenAssets::lines($session);
        $count = $screens->count();

        return array_values($screens->values()->map(fn (Screen $s, int $i): array => [
            'id' => (string) $s->id,
            'number' => $i + 1,
            'label' => __('Screen :n', ['n' => $i + 1]),
            'at' => TranscriptExporter::clock($s->at_ms),
            'at_ms' => (int) $s->at_ms,
            'description' => filled($s->description) ? (string) $s->description : null,
            'file_name' => ScreenAssets::fileName($s, $i + 1, $count),
            'said' => ScreenAssets::said($s, $lines),
            'thumb' => route('admin.screens.media', [$s->id, 'thumb']),
            'image' => route('admin.screens.media', [$s->id, 'image']),
        ])->all());
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Infolists;

use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Filament\Infolists\Components\Entry;

/**
 * HTML5 audio + transcript whose active segment highlights in sync with playback (click a line to seek).
 * Audio streams from the admin media route (session auth + MFA) with HTTP Range support.
 */
class TranscriptPlayerEntry extends Entry
{
    protected string $view = 'filament.infolists.transcript-player';

    /** @return array{audio_url: string|null, segments: list<array<string, mixed>>, kind: string|null, status: string|null, text: string|null} */
    public function getPlayerData(): array
    {
        $record = $this->getRecord();
        if (! $record instanceof CaptureSession) {
            return ['audio_url' => null, 'segments' => [], 'kind' => null, 'status' => null, 'text' => null];
        }

        $primary = $record->primaryRecording;
        $final = $record->transcripts()->where('kind', TranscriptKind::Final->value)->first();
        $live = $record->transcripts()->where('kind', TranscriptKind::Live->value)->first();
        /** @var Transcript|null $shown */
        $shown = ($final !== null && $final->segments) ? $final : ($live ?? $final);

        return [
            'audio_url' => $primary?->isReady() ? route('admin.recordings.stream', ['recording' => $primary->id]) : null,
            'segments' => $shown->segments ?? [],
            'kind' => $shown?->kind->value,
            'status' => $final?->status->value,
            'text' => $shown?->text,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Infolists;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Http\Resources\TranscriptResource;
use App\Models\CaptureSession;
use App\Models\SessionMarker;
use App\Models\Transcript;
use App\Services\TranscriptEditor;
use App\Services\Views\TranscriptViews;
use App\Stt\Data\Word;
use App\Stt\LanguageChoice;
use Filament\Infolists\Components\Entry;

/**
 * The console's transcript player (v1.1): audio with speeds 0.5-2x, skip silence, auto-follow, the keys
 * Space / J / K / L / ← / →, speaker blocks with renamed speakers, the word being spoken highlighted, an activity
 * timeline per speaker with bookmarks and pauses, and inline editing of a finished transcript. Audio streams from the
 * admin media route (signed-in owner only) with HTTP Range support.
 */
class TranscriptPlayerEntry extends Entry
{
    protected string $view = 'filament.infolists.transcript-player';

    /** @return array<string, mixed> */
    public function getPlayerData(): array
    {
        $record = $this->getRecord();

        return $record instanceof CaptureSession ? self::payload($record) : self::payload(null);
    }

    /**
     * Everything the player shows; also sent to it after an edit (`el-transcript-updated`).
     *
     * @return array{audio_url: string|null, segments: list<array<string, mixed>>, speaker_names: array<string, string>, kind: string|null,
     *     status: string|null, editable: bool, edited: bool, model: string|null, language: string, markers: list<array{at_ms: int, tag: string|null, note: string|null}>,
     *     pauses: list<array{at_ms: int, paused_ms: int}>, views: array<string, mixed>|null, duration_ms: int}
     */
    public static function payload(?CaptureSession $record): array
    {
        if ($record === null) {
            return ['audio_url' => null, 'segments' => [], 'speaker_names' => [], 'kind' => null, 'status' => null, 'editable' => false,
                'edited' => false, 'model' => null, 'language' => LanguageChoice::label(null), 'markers' => [], 'pauses' => [], 'views' => null, 'duration_ms' => 1];
        }
        $record->loadMissing(['primaryRecording', 'client']);
        $primary = $record->primaryRecording;
        $final = $record->transcripts()->where('kind', TranscriptKind::Final->value)->first();
        $live = $record->transcripts()->where('kind', TranscriptKind::Live->value)->first();
        /** @var Transcript|null $shown */
        $shown = ($final !== null && $final->segments) ? $final : ($live ?? $final);
        $segments = [];
        foreach ($shown->segments ?? [] as $s) {
            $words = [];
            foreach (is_array($s['words'] ?? null) ? $s['words'] : [] as $w) {
                if (is_array($w) && isset($w['start_ms'], $w['text'])) {
                    // `u`: the engine was not sure of the word (underlined; a click plays it).
                    $words[] = ['s' => (int) $w['start_ms'], 'e' => (int) ($w['end_ms'] ?? $w['start_ms']), 't' => (string) $w['text'],
                        ...(Word::isUncertain($w['confidence'] ?? null) ? ['u' => true] : [])];
                }
            }
            $segments[] = [
                'speaker' => (string) ($s['speaker'] ?? 'Speaker'),
                'channel' => (int) ($s['channel'] ?? 1) === 2 ? 2 : 1,
                'start_ms' => (int) ($s['start_ms'] ?? 0),
                'end_ms' => (int) ($s['end_ms'] ?? 0),
                'text' => (string) ($s['text'] ?? ''),
                'words' => $words,
                'uncertain' => (bool) ($s['uncertain'] ?? false),
                // Double-check: what a second engine heard on this line when it disagreed (shown under it, never merged).
                'check' => is_array($s['check'] ?? null) && isset($s['check']['text'])
                    ? ['label' => (string) ($s['check']['label'] ?? 'Second engine'), 'text' => (string) $s['check']['text']] : null,
            ];
        }

        return [
            'audio_url' => $primary?->isReady() ? route('admin.recordings.stream', ['recording' => $primary->id]) : null,
            'segments' => $segments,
            'speaker_names' => app(TranscriptEditor::class)->speakerNames($record),
            'kind' => $shown?->kind->value,
            'status' => $final?->status->value,
            'editable' => $shown !== null && $shown === $final && $final->status === TranscriptStatus::Completed,
            'edited' => $final?->edited_at !== null,
            'model' => $final !== null ? TranscriptResource::model($final) : null,
            'language' => LanguageChoice::label($final->language ?? $record->language),
            'markers' => $record->markers()->get(['at_ms', 'tag', 'note'])
                ->map(fn (SessionMarker $m): array => ['at_ms' => $m->at_ms, 'tag' => $m->tag, 'note' => $m->note])->values()->all(),
            'pauses' => $record->pauses ?? [],
            // Show as: the Roman Urdu and English views of the final transcript, when they are made (labelled as views).
            'views' => $final !== null && $shown === $final && $final->status === TranscriptStatus::Completed ? app(TranscriptViews::class)->state($final) : null,
            'duration_ms' => max(1, (int) ($record->duration_ms ?? 0), ...array_map(fn (array $s): int => $s['end_ms'], $segments ?: [['end_ms' => 0]])),
        ];
    }
}

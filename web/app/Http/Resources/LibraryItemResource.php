<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Http\Controllers\Api\SessionController;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Transcript;
use App\Models\User;
use App\Services\LibraryOrganiser;
use App\Services\RetentionPolicy;
use App\Services\TranscribeLater;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Library item (docs/API.md "Library & sync"). Tombstones carry only id, client_ref, deleted and updated_at.
 *
 * @mixin CaptureSession
 */
final class LibraryItemResource extends JsonResource
{
    /**
     * The transcript columns a row reads (plan B6): every one but the lines (`segments`) and the folded copy search
     * reads (`search_text`), the two largest, which no row uses. `GET /api/library` loads only these, about 40 % fewer
     * bytes a page with real calls. A new transcript column must be added here (a test compares it with the table).
     */
    public const TRANSCRIPT_COLUMNS = ['id', 'capture_session_id', 'kind', 'provider', 'status', 'provider_job_id', 'provider_transcript_id',
        'provider_deleted_at', 'language', 'text', 'meta', 'error', 'error_code', 'attempts', 'submitted_at', 'completed_at', 'edited_at',
        'created_at', 'updated_at'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CaptureSession $session */
        $session = $this->resource;

        if ($session->trashed()) {
            return [
                'id' => $session->id,
                'client_ref' => $session->client_ref,
                'deleted' => true,
                'updated_at' => $session->updated_at->toAtomString(),
            ];
        }

        $transcripts = $session->relationLoaded('transcripts') ? $session->transcripts : $session->transcripts()->get();
        $markers = $session->relationLoaded('markers') ? $session->markers : $session->markers()->get(['id', 'capture_session_id', 'tag', 'at_ms']);
        /** @var Transcript|null $final */
        $final = $transcripts->first(fn (Transcript $t) => $t->kind === TranscriptKind::Final);
        /** @var Transcript|null $live */
        $live = $transcripts->first(fn (Transcript $t) => $t->kind === TranscriptKind::Live);
        $other = $transcripts->first(fn (Transcript $t) => $t->kind === TranscriptKind::Alternate);

        return [
            'id' => $session->id,
            'client_ref' => $session->client_ref,
            'deleted' => false,
            'source_device' => $session->source_device->value,
            'source_device_label' => $session->source_device->getLabel(),
            'source_device_icon' => $session->source_device->iconKey(),
            'source_app' => $session->source_app->value,
            'source_app_label' => $session->source_app->getLabel(),
            'device' => $session->device ? ['id' => $session->device->id, 'name' => $session->device->name] : null,
            'client' => $session->client ? ['id' => $session->client->id, 'name' => $session->client->name, 'color' => $session->client->color, 'avatar' => $session->client->avatar] : null,
            // Since v1.5: how it got into its project (user, contact, rule), null in the Inbox.
            'filed_by' => $session->client !== null ? $session->filed_by : null,
            'title' => $session->displayTitle(),
            // Since v1.5 (Phase 1C): the title was written by the server from what was said; a person's title is false.
            'title_auto' => (bool) $session->title_auto,
            // Since v1.2: who the recording was with (name, number, where the device saw it), or null.
            'caller' => SessionController::caller($session),
            'started_at' => $session->started_at->toAtomString(),
            'ended_at' => $session->ended_at?->toAtomString(),
            'duration_ms' => $session->duration_ms,
            'status' => $session->status->value,
            // Since v1.1.2: the language chosen for the recording (null = automatic) and its chip ("UR+EN").
            'language' => LanguageChoice::normalize($session->language),
            'language_chip' => LanguageChoice::chip($final->language ?? $session->language),
            'transcript' => [
                'final_status' => $final?->status->value,
                'final_transcript_id' => $final?->id,
                'live_available' => $live !== null,
                'live_transcript_id' => $live?->id,
                'preview' => ($final?->text ? $final->preview() : $live?->preview()) ?: null,
                // Since v1.1.2: which language and engine produced the final transcript, and a wrong-script warning.
                'final_language' => $final?->language,
                'final_provider' => $final?->provider,
                'final_provider_label' => $final?->providerKey()?->getLabel(),
                'final_model' => $final !== null ? TranscriptResource::model($final) : null,
                'live_language' => $live?->language,
                'script_warning' => $final?->meta['script_warning'] ?? null,
                // Since v1.1.2: why the final transcript failed (no_audio: the recording has no sound; apps show "No audio").
                'final_error_code' => $final?->status === TranscriptStatus::Failed ? ($final->error_code ?: null) : null,
                // Since v1.2: the other version (the previous transcript after Re-transcribe as, or a second engine's run).
                'alternate_transcript_id' => $other?->id,
                'alternate_status' => $other?->status->value,
                'alternate_language' => $other?->language,
                // Since v1.2: parts done of all parts while a long recording is transcribed in pieces.
                'final_progress' => TranscriptResource::progress($final),
                // Since v1.5 (Phase 1C): the views of a finished transcript in the row, so it can say "Improving Roman
                // Urdu 60 %": {"status": "making", "default": "roman", "progress": {"roman": {"done": 12, "total": 20}}}.
                'views' => TranscriptViews::rowSummary($final),
            ],
            'marker_count' => $markers->count(),
            // Since v1.5: screen moments kept during the meeting (counted with the query where it was asked for).
            'screen_count' => (int) ($session->getAttribute('screens_count') ?? $session->screens()->count()),
            'tags' => $markers->pluck('tag')->filter()->unique()->values()->all(),
            'pinned' => (bool) $session->pinned,
            'keep' => (bool) $session->keep_forever,
            'labels' => $session->labelList(),
            // Since v1.3: whether the asking user has opened it (the apps show a dot until then); left out where unknown.
            'opened' => $this->when($session->relationLoaded('opens'), fn (): bool => $session->opens->isNotEmpty()),
            // Since 2.0.0-i: the person's flag, the colours of its labels that have one, and when retention moves it to Trash.
            'important' => (bool) $session->important,
            'label_colors' => (object) self::labelColors($session),
            'expires_at' => app(RetentionPolicy::class)->expiresAt($session)?->toAtomString(),
            // Since v1.8 (contract 1.8.0-e): what transcribing an audio-only recording would use; null otherwise.
            // Only for the person whose recording it is: the minutes left are theirs, never a sender's (a recording a
            // teammate sent shows none).
            'transcribe_estimate' => self::asksOwner($request, $session) ? app(TranscribeLater::class)->estimate($session) : null,
            'updated_at' => $session->updated_at->toAtomString(),
        ];
    }

    /** Whether the person asking (a device's, or the signed-in console user) made [$session], in its workspace. */
    private static function asksOwner(Request $request, CaptureSession $session): bool
    {
        $asker = $request->user();
        [$id, $workspace] = match (true) {
            $asker instanceof Device => [(int) $asker->user_id, (int) $asker->workspace_id],
            $asker instanceof User => [(int) $asker->id, (int) CurrentWorkspace::id()],
            default => [null, null],
        };

        return $id !== null && $id === (int) $session->user_id && $workspace === (int) $session->workspace_id;
    }

    /**
     * The colours of [$session]'s labels that have one, label as the recording spells it => "#rrggbb" (2.0.0-i).
     *
     * @return array<string, string>
     */
    public static function labelColors(CaptureSession $session): array
    {
        $labels = $session->labelList();
        if ($labels === []) {
            return [];
        }
        // A label belongs to the person who made the recording, so its colour is theirs (a sent recording keeps its sender's).
        $colors = app(LibraryOrganiser::class)->labelColors((int) $session->workspace_id, (int) $session->user_id);
        $out = [];
        foreach ($labels as $label) {
            if (isset($colors[mb_strtolower($label)])) {
                $out[$label] = $colors[mb_strtolower($label)];
            }
        }

        return $out;
    }
}

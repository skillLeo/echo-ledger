<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Re-transcribe and compare (since v1.1, console): a second engine transcribes a finished recording, both versions
 * are kept (the second as `alternate`), they are compared side by side, and either can become the final one. A new
 * second version replaces the previous one; the provider's copy of the replaced job is deleted as usual.
 */
final class Retranscriber
{
    public function start(CaptureSession $session, SttProviderKey $provider, ?string $language = null): Transcript
    {
        $final = $session->finalTranscript;
        if (! $final instanceof Transcript || $final->status !== TranscriptStatus::Completed) {
            throw new RuntimeException('Re-transcribe needs a finished transcript to compare with.');
        }
        if ($session->recordings()->where('role', 'primary')->first()?->isReady() !== true) {
            throw new RuntimeException('The recording is not on the server any more, so it cannot be transcribed again.');
        }
        $previous = $session->transcripts()->where('kind', TranscriptKind::Alternate->value)->first();
        if ($previous instanceof Transcript) {
            $this->discard($previous);
        }
        $alternate = $session->transcripts()->create([
            'kind' => TranscriptKind::Alternate,
            'provider' => $provider->value,
            'status' => TranscriptStatus::Pending,
            'language' => LanguageChoice::normalize($language),
        ]);
        TranscribeFinalJob::dispatch($alternate->id);

        return $alternate;
    }

    /**
     * "Re-transcribe as <language>" (since v1.2, apps and console): the recording's language becomes [$language] and
     * the engine for it runs again. The current transcript stays until the new one is ready; then the new one becomes
     * the transcript (newest is the default) and the old one stays as the other version. Without a finished
     * transcript (failed or never made) the final one simply runs again in the new language.
     */
    public function startAs(CaptureSession $session, ?string $language): Transcript
    {
        $language = LanguageChoice::normalize($language);
        if ($session->recordings()->where('role', 'primary')->first()?->isReady() !== true) {
            throw new RuntimeException('The recording is not on the server any more, so it cannot be transcribed again.');
        }
        $session->update(['language' => $language]);
        $final = $session->finalTranscript;
        if (! $final instanceof Transcript || $final->status !== TranscriptStatus::Completed) {
            return app(FinalTranscriptCoordinator::class)->start($session->refresh(), $final);
        }
        $route = app(LanguageRouter::class)->final($language ?? LanguageChoice::normalize($session->client?->language), FinalTranscriptCoordinator::useCaseFor($session), $session);
        $alternate = $this->start($session, $route->provider, $language);
        $alternate->update(['meta' => ['promote_when_done' => true, 'route' => $route->toArray()]]);

        return $alternate->refresh();
    }

    /** Makes the second version the final one (the old final becomes the second version). */
    public function choose(Transcript $alternate): void
    {
        if ($alternate->kind !== TranscriptKind::Alternate || $alternate->status !== TranscriptStatus::Completed) {
            throw new RuntimeException('Only a finished second version can become the final transcript.');
        }
        $session = $alternate->captureSession;
        DB::transaction(function () use ($alternate, $session): void {
            $final = $session->transcripts()->where('kind', TranscriptKind::Final->value)->first();
            // (session, kind) is unique: park the final under a temporary kind while the two change places.
            if ($final instanceof Transcript) {
                DB::table('transcripts')->where('id', $final->id)->update(['kind' => 'swap']);
            }
            DB::table('transcripts')->where('id', $alternate->id)->update(['kind' => TranscriptKind::Final->value, 'updated_at' => now()]);
            if ($final instanceof Transcript) {
                DB::table('transcripts')->where('id', $final->id)->update(['kind' => TranscriptKind::Alternate->value, 'updated_at' => now()]);
            }
            $session->update(['status' => SessionStatus::Completed, 'final_provider' => $alternate->provider]);
            $session->touch();
        });
    }

    /** Removes a second version (and asks the provider to delete its copy). */
    public function discard(Transcript $alternate): void
    {
        if ($alternate->kind !== TranscriptKind::Alternate) {
            return;
        }
        $provider = $alternate->providerKey();
        if ($alternate->provider_job_id !== null && $alternate->provider_job_id !== '' && $provider !== null && $alternate->provider_deleted_at === null) {
            app(ProviderCopyEraser::class)->schedule($provider, $alternate->provider_job_id, $alternate->provider_transcript_id);
        }
        $alternate->delete();
    }
}

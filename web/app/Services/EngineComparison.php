<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Http\Resources\TranscriptResource;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use App\Stt\ScriptCheck;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compare engines (console, since v1.1.2): the same recording runs through up to three engines for one language, each
 * kept as a `trial` transcript (never the recording's transcript, never searched or shared). The result lists each
 * run with its text and cost; the winner becomes the first engine for that language, and can also become the
 * recording's transcript (the old one is kept as the second version).
 */
final class EngineComparison
{
    public const MAX_ENGINES = 3;

    public function __construct(private readonly LanguageRouter $router, private readonly ProviderCopyEraser $eraser) {}

    /**
     * Starts one run per engine; an earlier run of the same engine is replaced.
     *
     * @param  list<SttProviderKey>  $engines
     * @return list<Transcript>
     */
    public function start(CaptureSession $session, array $engines, ?string $language): array
    {
        if ($session->recordings()->where('role', 'primary')->first()?->isReady() !== true) {
            throw new RuntimeException('The recording is not on the server any more, so it cannot be transcribed again.');
        }
        $language = LanguageChoice::normalize($language);
        $allowed = $this->router->candidates($language, $session);
        $engines = array_values(array_unique(array_filter($engines, fn (SttProviderKey $e): bool => in_array($e, $allowed, true)), SORT_REGULAR));
        if ($engines === []) {
            throw new RuntimeException('None of these engines can transcribe '.LanguageChoice::label($language).' here. Add their keys on API Credentials.');
        }
        $engines = array_slice($engines, 0, self::MAX_ENGINES);

        $runs = [];
        foreach ($engines as $engine) {
            $previous = $session->trials()->where('provider', $engine->value)->first();
            if ($previous instanceof Transcript) {
                $this->discard($previous);
            }
            $run = $session->allTranscripts()->create([
                'kind' => TranscriptKind::Trial,
                'provider' => $engine->value,
                'status' => TranscriptStatus::Pending,
                'language' => $language,
                'meta' => ['comparison' => true],
            ]);
            TranscribeFinalJob::dispatch($run->id);
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * The runs of a recording, side by side.
     *
     * @return list<array{id: string, provider: string, label: string, model: string|null, status: string, error: string|null, language: string, cost_usd: float|null, minutes: float|null, script_warning: string|null, text: string}>
     */
    public function results(CaptureSession $session): array
    {
        $runs = $session->trials()->orderBy('created_at')->get();
        $costs = UsageLedgerEntry::query()->whereIn('transcript_id', $runs->pluck('id'))
            ->selectRaw('transcript_id, SUM(cost_usd) as cost, SUM(billed_ms) as billed')->groupBy('transcript_id')->get()->keyBy('transcript_id');

        return $runs->map(function (Transcript $run) use ($costs): array {
            $row = $costs->get($run->id);
            $warning = $run->meta['script_warning'] ?? null;

            return [
                'id' => $run->id,
                'provider' => $run->provider,
                'label' => $run->providerKey()?->getLabel() ?? $run->provider,
                'model' => TranscriptResource::model($run),
                'status' => $run->status->value,
                'error' => $run->status === TranscriptStatus::Failed ? $run->error : null,
                'language' => LanguageChoice::label($run->language),
                'cost_usd' => $row !== null ? round((float) $row->getAttribute('cost'), 4) : null,
                'minutes' => $row !== null ? round(((int) $row->getAttribute('billed')) / 60000, 1) : null,
                'script_warning' => is_string($warning) ? ScriptCheck::label($warning) : null,
                'text' => (string) $run->text,
            ];
        })->values()->all();
    }

    /** The winner: first engine for its language from now on. */
    public function pick(Transcript $run): void
    {
        $this->assertRun($run);
        if ($run->language === null) {
            throw new RuntimeException('Choose a language to compare engines for; Automatic has no engine list.');
        }
        $this->router->prefer($run->language, SttProviderKey::from($run->provider));
    }

    /** The winner's text becomes the recording's transcript; the current one is kept as the second version. */
    public function useAsTranscript(Transcript $run): void
    {
        $this->assertRun($run);
        if ($run->status !== TranscriptStatus::Completed) {
            throw new RuntimeException('Only a finished run can become the transcript.');
        }
        $session = $run->captureSession;
        DB::transaction(function () use ($run, $session): void {
            $alternate = $session->transcripts()->where('kind', TranscriptKind::Alternate->value)->first();
            if ($alternate instanceof Transcript) {
                $this->discard($alternate);
            }
            $final = $session->transcripts()->where('kind', TranscriptKind::Final->value)->first();
            if ($final instanceof Transcript) {
                DB::table('transcripts')->where('id', $final->id)->update(['kind' => TranscriptKind::Alternate->value, 'updated_at' => now()]);
            }
            DB::table('transcripts')->where('id', $run->id)->update(['kind' => TranscriptKind::Final->value, 'updated_at' => now()]);
            $session->update(['status' => SessionStatus::Completed, 'final_provider' => $run->provider, 'language' => $run->language ?? $session->language]);
            $session->touch();
        });
    }

    private function assertRun(Transcript $run): void
    {
        if ($run->kind !== TranscriptKind::Trial) {
            throw new RuntimeException('That is not a comparison run.');
        }
    }

    private function discard(Transcript $transcript): void
    {
        $provider = $transcript->providerKey();
        if ($transcript->provider_job_id !== null && $transcript->provider_job_id !== '' && $provider !== null && $transcript->provider_deleted_at === null) {
            $this->eraser->schedule($provider, $transcript->provider_job_id, $transcript->provider_transcript_id);
        }
        $transcript->delete();
    }
}

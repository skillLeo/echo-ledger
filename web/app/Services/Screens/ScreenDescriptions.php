<?php

declare(strict_types=1);

namespace App\Services\Screens;

use App\Models\CaptureSession;
use App\Models\Screen;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Support\UrduText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The text and one-line description of each screen moment (v1.5), made on the queue with Claude Haiku 5.5 when
 * Settings → Screen moments → "Describe screens" is on (the default) and an Anthropic key is set. They make screens
 * searchable and are billed to the workspace in the usage ledger. A failure leaves both empty and never blocks
 * anything else.
 */
final class ScreenDescriptions
{
    public const SETTING = 'screens.describe';

    public const USE_CASE = 'screen_describe';

    public const PROVIDER = 'anthropic';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CredentialVault $vault,
        private readonly ScreenStore $store,
        private readonly ScreenDescriber $describer,
    ) {}

    public function enabled(): bool
    {
        $key = $this->vault->get('anthropic', 'api_key');

        return (bool) $this->settings->get(self::SETTING, true) && is_string($key) && $key !== '';
    }

    /**
     * Reads the screen and keeps its text and description; false when it was not done (off, gone, or a failure). A
     * copy ("Save to my library", `$copyOf` its original) takes the original's answer when it has one, unbilled.
     */
    public function describe(Screen $screen, ?string $copyOf = null): bool
    {
        $session = CaptureSession::query()->withoutGlobalScopes()->find($screen->capture_session_id);
        if (! $this->enabled() || ! $session instanceof CaptureSession || $session->trashed()) {
            return false; // switched off since it was queued, or the recording was deleted
        }
        $original = $copyOf !== null ? Screen::query()->withoutGlobalScopes()->find($copyOf) : null;
        if ($original instanceof Screen && $original->described_at !== null && $original->sha256 === $screen->sha256) {
            $screen->forceFill($original->only(['ocr_text', 'description', 'search_text', 'described_at']))->save();

            return true;
        }
        try {
            $bytes = $this->store->picture($screen);
            if ($bytes === null) {
                return false;
            }
            $answer = $this->describer->describe($bytes, $screen->mime);
        } catch (Throwable $e) {
            Log::warning('Screen description failed', ['screen' => $screen->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return false;
        }
        $text = $answer['ocr_text'] !== '' ? $answer['ocr_text'] : null;
        $description = $answer['description'] !== '' ? $answer['description'] : null;
        $screen->forceFill([
            'ocr_text' => $text,
            'description' => $description,
            'search_text' => self::searchText($text, $description),
            'described_at' => now(),
        ])->save();
        $this->bill($session, $screen, $answer);

        return true;
    }

    /** The text search reads: the screen's text and description, folded like transcripts. */
    public static function searchText(?string $text, ?string $description): ?string
    {
        $joined = trim(($description ?? '')."\n".($text ?? ''));

        return $joined === '' ? null : UrduText::fold($joined);
    }

    /** @param  array{input_tokens: int, output_tokens: int, model: string}  $usage */
    private function bill(CaptureSession $session, Screen $screen, array $usage): void
    {
        [$in, $out] = ClaudeScreenDescriber::PRICES[$usage['model']] ?? ClaudeScreenDescriber::PRICES[ClaudeScreenDescriber::MODEL];
        UsageLedgerEntry::query()->withoutGlobalScopes()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'device_id' => $session->device_id,
            'capture_session_id' => $session->id,
            'client_id' => $session->client_id,
            'report_id' => 'screens/'.$screen->id.'/'.Str::ulid(),
            'source' => UsageLedgerEntry::SOURCE_SERVER,
            'use_case' => self::USE_CASE,
            'provider' => self::PROVIDER,
            'model' => mb_substr($usage['model'], 0, 64),
            'duration_ms' => 0,
            'channels' => 1,
            'billed_ms' => 0,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'unit_price_usd' => 0,
            'cost_usd' => round(($usage['input_tokens'] * $in + $usage['output_tokens'] * $out) / 1_000_000, 6),
            'billable' => true,
            'occurred_at' => now(),
        ]);
    }
}

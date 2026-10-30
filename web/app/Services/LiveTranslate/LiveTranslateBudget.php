<?php

declare(strict_types=1);

namespace App\Services\LiveTranslate;

use App\Enums\SttUseCase;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Services\SettingsRepository;
use App\Services\Usage\UsagePeriod;

/**
 * Live Translate's own monthly budget (plan B1), kept apart from the transcription limit so a game night never stops
 * call transcripts: what this month's Game and Watch translate cost so far, against `live_translate.monthly_usd`.
 */
final class LiveTranslateBudget
{
    public const WARN_AT = 0.8;

    public const SPEEDS = ['fastest', 'balanced', 'accurate'];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return list<string> */
    public static function useCases(): array
    {
        return [SttUseCase::GameTranslate->value, SttUseCase::WatchTranslate->value];
    }

    public function limitUsd(): ?float
    {
        $value = $this->settings->get('live_translate.monthly_usd');

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    public function spentUsd(int $workspaceId): float
    {
        $period = UsagePeriod::current($this->settings);

        return (float) UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereIn('use_case', self::useCases())
            ->where('occurred_at', '>=', $period['start'])
            ->where('occurred_at', '<', $period['end'])
            ->sum('cost_usd');
    }

    /** @return array{used_usd: float, limit_usd: float|null, warn: bool, reached: bool} */
    public function state(int $workspaceId): array
    {
        $used = $this->spentUsd($workspaceId);
        $limit = $this->limitUsd();

        return [
            'used_usd' => round($used, 2),
            'limit_usd' => $limit,
            'warn' => $limit !== null && $used >= $limit * self::WARN_AT,
            'reached' => $limit !== null && $used >= $limit,
        ];
    }

    /**
     * The console's defaults for the phone's Game and Watch panels (used until the person changes them there).
     *
     * @return array{model: string, monthly_usd: float|null, game: array{target: string, spoken: list<string>, speed: string}, watch: array{target: string, speed: string}}
     */
    public function defaults(): array
    {
        $lang = fn (mixed $v, string $fallback): string => is_string($v) && preg_match('/^[a-z]{2}$/', $v) === 1 ? $v : $fallback;
        $speed = fn (mixed $v, string $fallback): string => in_array($v, self::SPEEDS, true) ? $v : $fallback;
        $spoken = array_values(array_filter((array) $this->settings->get('live_translate.game_spoken', ['ar', 'en']), fn (mixed $v): bool => is_string($v) && preg_match('/^[a-z]{2}$/', $v) === 1));

        return [
            'model' => (string) $this->settings->get('live_translate.model', 'stt-rt-v5'),
            'monthly_usd' => $this->limitUsd(),
            'game' => [
                'target' => $lang($this->settings->get('live_translate.game_target'), 'en'),
                'spoken' => array_slice($spoken, 0, 4),
                'speed' => $speed($this->settings->get('live_translate.game_speed'), 'fastest'),
            ],
            'watch' => [
                'target' => $lang($this->settings->get('live_translate.watch_target'), 'ur'),
                'speed' => $speed($this->settings->get('live_translate.watch_speed'), 'balanced'),
            ],
        ];
    }
}

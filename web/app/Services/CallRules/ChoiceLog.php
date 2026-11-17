<?php

declare(strict_types=1);

namespace App\Services\CallRules;

use App\Models\CaptureSession;
use App\Services\Entitlements\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * The admin's counts of calls transcribed, kept as audio, deleted by the person and removed by clean-up, per plan
 * (contract 2.1.0-a). Only the plan, workspace, person, outcome, who decided and when: no content, names or numbers.
 */
final class ChoiceLog
{
    public const TRANSCRIBED = 'transcribed';

    public const KEPT = 'kept';

    public const DELETED = 'deleted';

    public const CLEANED = 'cleaned';

    public const OUTCOMES = [self::TRANSCRIBED, self::KEPT, self::DELETED, self::CLEANED];

    public static function record(CaptureSession $session, string $outcome, ?string $by = null): void
    {
        $workspaceId = (int) $session->workspace_id;
        DB::table('call_choice_log')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $session->user_id,
            'plan_key' => app(Entitlements::class)->plan($workspaceId)->key,
            'outcome' => $outcome,
            'by' => $by,
            'created_at' => now(),
        ]);
    }

    /**
     * Counts per plan and outcome since [$from].
     *
     * @return array<string, array<string, int>> plan key => outcome => count
     */
    public static function counts(\DateTimeInterface $from, ?\DateTimeInterface $to = null): array
    {
        $rows = DB::table('call_choice_log')->where('created_at', '>=', $from)
            ->when($to !== null, fn ($q) => $q->where('created_at', '<', $to))
            ->selectRaw('plan_key, outcome, count(*) as n')->groupBy('plan_key', 'outcome')->get();
        $out = [];
        foreach ($rows as $row) {
            $plan = (string) ($row->plan_key ?? 'none');
            $out[$plan] ??= array_fill_keys(self::OUTCOMES, 0);
            $out[$plan][(string) $row->outcome] = (int) $row->n;
        }

        return $out;
    }
}

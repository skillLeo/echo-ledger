<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The v1.6 plans (P1.3, docs/API.md "Entitlements"), made or brought up to date here and editable on the Plans page
 * afterwards:
 * - Free (price 0, the default for sign-ups): the free features only; 2 devices, 1 GB, 30 days, no minutes of the
 *   server's own transcription (trial minutes 0, the admin may raise them): the person's own Groq key or the computer.
 * - Pro: transcription on EchoLedger (600 minutes a month), the views, double-check, screen moments, the connector;
 *   5 devices, 20 GB, 365 days.
 * - Business: Pro and a team, premium engines, Live Translate, AI notes; 100 GB.
 * Nobody loses anything they have: every workspace on Personal or on the v1.5 Free plan moves to Business, and the super
 * admins (the operator) get no limit at all (their own overrides). Workspaces on a plan the admin made stay on it.
 * Retention of the three plans applies only to recordings made from now on (`retention_from`): no existing recording is
 * ever deleted by it. Personal stays (unused, not on offer).
 *
 * What was there before (the old Free row, which workspaces moved, the super admins' overrides) is kept in the table
 * `plans_before_v16`, which down() puts back and drops.
 */
return new class extends Migration
{
    private const GB = 1024 ** 3;

    private const PAID = ['platform_transcription', 'views', 'double_check', 'screen_moments', 'connector', 'team', 'premium_engines', 'live_translate', 'ai_notes'];

    private const COLUMNS = ['name', 'badge', 'color', 'description', 'price_usd', 'price_label', 'sort_order', 'on_offer', 'is_default', 'trial_minutes',
        'monthly_minutes', 'storage_bytes', 'max_devices', 'retention_days', 'seats', 'ai_credits_usd', 'max_file_minutes', 'uploads_per_day',
        'engines', 'features', 'retention_from'];

    /** @return array<string, array<string, mixed>> */
    private function plans(): array
    {
        $features = fn (array $on): string => (string) json_encode(array_combine(self::PAID, array_map(fn (string $f): bool => in_array($f, $on, true), self::PAID)));
        $pro = ['platform_transcription', 'views', 'double_check', 'screen_moments', 'connector'];

        return [
            'free' => [
                'name' => 'Free', 'badge' => 'FREE', 'color' => '#64748b', 'price_usd' => 0, 'price_label' => 'Free', 'sort_order' => 0,
                'description' => 'Record, organise, search and share. Transcripts with your own free Groq key, or on your computer.',
                'on_offer' => true, 'is_default' => true, 'trial_minutes' => 0,
                'monthly_minutes' => 0, 'storage_bytes' => self::GB, 'max_devices' => 2, 'retention_days' => 30, 'seats' => 1, 'ai_credits_usd' => 0,
                'max_file_minutes' => 180, 'uploads_per_day' => 50, 'engines' => json_encode(['own_groq', 'on_device']), 'features' => $features([]),
            ],
            'pro' => [
                'name' => 'Pro', 'badge' => 'PRO', 'color' => '#7c3aed', 'price_usd' => 0, 'price_label' => 'Ask for the price', 'sort_order' => 10,
                'description' => 'Transcripts made for you, Roman Urdu and English views, double-check, screen moments and Claude or ChatGPT.',
                'on_offer' => true, 'is_default' => false, 'trial_minutes' => 0,
                'monthly_minutes' => 600, 'storage_bytes' => 20 * self::GB, 'max_devices' => 5, 'retention_days' => 365, 'seats' => 1, 'ai_credits_usd' => 0,
                'max_file_minutes' => 240, 'uploads_per_day' => 200, 'engines' => json_encode(['own_groq', 'on_device', 'assemblyai', 'groq', 'soniox', 'azure_mai']),
                'features' => $features($pro),
            ],
            'business' => [
                'name' => 'Business', 'badge' => 'BUSINESS', 'color' => '#0369a1', 'price_usd' => 0, 'price_label' => 'Ask for the price', 'sort_order' => 20,
                'description' => 'Everything in Pro, and a team with roles, premium engines, Live Translate and AI notes.',
                'on_offer' => true, 'is_default' => false, 'trial_minutes' => 0,
                'monthly_minutes' => 3000, 'storage_bytes' => 100 * self::GB, 'max_devices' => 5, 'retention_days' => 365, 'seats' => 10, 'ai_credits_usd' => 10,
                'max_file_minutes' => 480, 'uploads_per_day' => 500, 'engines' => null, 'features' => $features(self::PAID),
            ],
        ];
    }

    public function up(): void
    {
        $now = now();
        $before = ['plans' => [], 'created' => [], 'moved' => [], 'overrides' => []];
        foreach ($this->plans() as $key => $values) {
            $row = DB::table('plans')->where('key', $key)->first();
            $values = [...$values, 'retention_from' => $now, 'updated_at' => $now];
            if ($row === null) {
                DB::table('plans')->insert(['key' => $key, ...$values, 'created_at' => $now]);
                $before['created'][] = $key;
            } else {
                $before['plans'][$key] = array_intersect_key((array) $row, array_flip(self::COLUMNS));
                DB::table('plans')->where('id', $row->id)->update($values);
            }
        }
        // One default plan: Free.
        DB::table('plans')->where('key', '!=', 'free')->update(['is_default' => false]);
        DB::table('plans')->where('key', 'personal')->update(['on_offer' => false]);

        $business = (int) DB::table('plans')->where('key', 'business')->value('id');
        $old = DB::table('plans')->whereIn('key', ['personal', 'free'])->pluck('id')->all();
        DB::table('workspaces')->whereIn('plan_id', $old)->orderBy('id')->get(['id', 'plan_id'])->each(function ($workspace) use (&$before, $business): void {
            $before['moved'][(string) $workspace->id] = (int) $workspace->plan_id;
            DB::table('workspaces')->where('id', $workspace->id)->update(['plan_id' => $business]);
        });

        $none = json_encode(['limits' => array_fill_keys(['monthly_minutes', 'devices', 'storage_bytes', 'retention_days', 'seats', 'max_file_minutes', 'uploads_per_day', 'ai_usd_month'], null)]);
        DB::table('users')->where('is_super_admin', true)->orderBy('id')->get(['id', 'entitlement_overrides'])->each(function ($user) use (&$before, $none): void {
            $before['overrides'][(string) $user->id] = $user->entitlement_overrides;
            DB::table('users')->where('id', $user->id)->update(['entitlement_overrides' => $none]);
        });

        Schema::create('plans_before_v16', function (Blueprint $table): void {
            $table->id();
            $table->json('state');
            $table->timestamps();
        });
        DB::table('plans_before_v16')->insert(['state' => json_encode($before), 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $raw = Schema::hasTable('plans_before_v16') ? DB::table('plans_before_v16')->orderByDesc('id')->value('state') : null;
        $before = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($before)) {
            Schema::dropIfExists('plans_before_v16');

            return;
        }
        foreach ((array) ($before['overrides'] ?? []) as $userId => $overrides) {
            DB::table('users')->where('id', (int) $userId)->update(['entitlement_overrides' => $overrides]);
        }
        foreach ((array) ($before['moved'] ?? []) as $workspaceId => $planId) {
            DB::table('workspaces')->where('id', (int) $workspaceId)->update(['plan_id' => (int) $planId]);
        }
        foreach ((array) ($before['plans'] ?? []) as $key => $values) {
            DB::table('plans')->where('key', $key)->update((array) $values);
        }
        foreach ((array) ($before['created'] ?? []) as $key) {
            $id = DB::table('plans')->where('key', $key)->value('id');
            if ($id !== null && ! DB::table('workspaces')->where('plan_id', $id)->exists()) {
                DB::table('plans')->where('id', $id)->delete();
            } elseif ($id !== null) {
                DB::table('plans')->where('id', $id)->update(['is_default' => false, 'on_offer' => false]);
            }
        }
        Schema::dropIfExists('plans_before_v16');
    }
};

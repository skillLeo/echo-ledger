<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Jobs\MakeAccountExportJob;
use App\Models\AccountExport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One export at a time per account (since v1.5): asking again while one is being made answers that one; a new export
 * replaces the previous ZIP. Ready ZIPs are deleted 24 hours after they are made (`echoledger:prune-exports`).
 */
final class AccountExports
{
    /** An export that died with its worker counts as failed after this long. */
    public const STALE_HOURS = 6;

    public function start(User $user): AccountExport
    {
        $making = AccountExport::query()->where('user_id', $user->id)->where('status', 'making')
            ->where('created_at', '>', now()->subHours(self::STALE_HOURS))->latest('created_at')->first();
        if ($making instanceof AccountExport) {
            return $making;
        }
        AccountExport::query()->where('user_id', $user->id)->get()->each(fn (AccountExport $old) => $this->remove($old));
        $export = AccountExport::query()->create(['user_id' => $user->id, 'status' => 'making']);
        MakeAccountExportJob::dispatch($export->id);

        return $export->refresh();
    }

    public function latest(User $user): ?AccountExport
    {
        return AccountExport::query()->where('user_id', $user->id)->latest('created_at')->latest('id')->first();
    }

    /** Ready exports past their 24 hours, and exports whose worker died; returns how many were removed. */
    public function prune(): int
    {
        $old = AccountExport::query()
            ->where(fn ($q) => $q->where('status', 'ready')->where('expires_at', '<=', now()))
            ->orWhere(fn ($q) => $q->where('status', 'failed')->where('updated_at', '<=', now()->subHours(AccountExport::HOURS)))
            ->orWhere(fn ($q) => $q->where('status', 'making')->where('created_at', '<=', now()->subHours(self::STALE_HOURS)))
            ->get();
        $old->each(fn (AccountExport $export) => $this->remove($export));

        return $old->count();
    }

    private function remove(AccountExport $export): void
    {
        if ($export->path !== null) {
            try {
                Storage::disk('recordings')->delete($export->path);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $export->delete();
    }
}

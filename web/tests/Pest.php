<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\User;
use App\Services\RetentionPolicy;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot the Laravel app and get a fresh in-memory schema per test.
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Unit tests are plain and fast (pure classes); they do not boot the framework.

/**
 * Ends the 48-hour undo bin (B17) for everything deleted so far and erases it, as the hourly retention run would.
 */
function emptyUndoBin(): void
{
    CaptureSession::onlyTrashed()->whereNull('media_erased_at')
        ->update(['deleted_at' => now()->subHours(CaptureSession::BIN_HOURS + 1)]);
    app(RetentionPolicy::class)->emptyBin();
}

/**
 * Reads the database as it is, across workspaces (B20): test code that checks what is stored must not be narrowed
 * by the workspace of whoever the last request authenticated.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function unscoped(Closure $callback): mixed
{
    return CurrentWorkspace::bypass($callback);
}

/** Takes away every workspace membership: a signed-in account that is neither a super admin nor a member (B20). */
function withoutWorkspace(User $user): User
{
    $user->workspaces()->detach();
    $user->forceFill(['current_workspace_id' => null])->save();

    return $user;
}

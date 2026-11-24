<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 2.0.0-r, BE-03: users.retry_requested_at, the person's latest Retry all (App\Services\UploadRetries), which
 * the apps read with the Activity list. Nullable, null for everyone at first; an instant add on MariaDB. down() drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'retry_requested_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->timestamp('retry_requested_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'retry_requested_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('retry_requested_at'));
        }
    }
};

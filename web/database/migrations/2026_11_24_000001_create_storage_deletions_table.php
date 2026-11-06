<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files in the recordings bucket that could not be deleted yet; stt:reconcile retries them. New table only, so it is
 * safe on a production database, and it stays empty while the recordings are on the server's own disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_deletions', function (Blueprint $table): void {
            $table->id();
            $table->string('path')->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_deletions');
    }
};

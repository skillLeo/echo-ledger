<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has opened which recording (since v1.3): the apps show a recording nobody of that user has opened yet with a
 * dot. One row per recording and user; opening again only moves `opened_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_opens', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('capture_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->unique(['capture_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_opens');
    }
};

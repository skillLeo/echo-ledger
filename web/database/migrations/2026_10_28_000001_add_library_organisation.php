<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Client folders (avatar, pin), pinned recordings and recording labels (plan B15). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('avatar', 16)->nullable()->after('color');
            $table->boolean('pinned')->default(false)->after('avatar');
        });

        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->boolean('pinned')->default(false)->after('status');
            $table->index(['user_id', 'pinned']);
        });

        Schema::create('session_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('capture_session_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->unique(['capture_session_id', 'label']);
            $table->index('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_labels');
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'pinned']);
            $table->dropColumn('pinned');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['avatar', 'pinned']);
        });
    }
};

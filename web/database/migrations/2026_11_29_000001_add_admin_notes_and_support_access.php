<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers on the admin panel (since v1.6, P2.2): staff notes about a person (never shown to them), and the person's
 * own Support access switch: until this time staff may open their recordings and transcripts, and every look is in the
 * audit log. Nobody starts with it on. Nothing existing changes, and down() removes exactly what up() added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('support_access_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('support_access_until');
        });
        Schema::dropIfExists('admin_notes');
    }
};

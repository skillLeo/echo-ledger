<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 2.0.0-p "Saved views": one person's saved Library views in one workspace (My views in the sidebar), kept by
 * the server so every device shows the same ones in the same order. A view holds the filters, the search and how the
 * list is shown; never a recording or any of its words. users.views_version moves with every change of a person's views,
 * so the library sync can say when to read them again (`views_version`) from the row it already reads. Same owner and
 * workspace keys as label_lists. Up only adds (the table, the column, when missing); down drops only what up added.
 * Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saved_views')) {
            Schema::create('saved_views', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // The app's own id for a view made offline: sending it again finds the same view.
                $table->string('client_ref', 64)->nullable();
                $table->string('name', 240);
                $table->text('filters');
                $table->string('search', 800)->nullable();
                $table->string('sort', 16);
                $table->string('group_by', 16);
                $table->string('density', 16);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['workspace_id', 'user_id', 'client_ref']);
                $table->index(['workspace_id', 'user_id', 'position']);
            });
        }
        if (! Schema::hasColumn('users', 'views_version')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('views_version')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'views_version')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('views_version');
            });
        }
        Schema::dropIfExists('saved_views');
    }
};

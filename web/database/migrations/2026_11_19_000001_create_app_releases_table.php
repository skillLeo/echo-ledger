<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The apps' builds on the downloads page (since v1.5, docs/API.md "Public pages and app downloads"), published by
 * `php artisan echoledger:publish-release`. Server-wide (no workspace); the files live on the "downloads" disk.
 * Rollback drops the table; the files stay on the disk until removed by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('platform', 20);
            $table->string('version', 40);
            $table->unsignedInteger('code')->nullable();
            $table->string('path', 255);
            $table->string('file_name', 120);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            // The desktop updater's file and its signature: the Windows installer itself, a Mac .app.tar.gz.
            $table->string('updater_path', 255)->nullable();
            $table->string('updater_file_name', 120)->nullable();
            $table->text('signature')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->useCurrent();
            $table->timestamps();
            $table->unique(['platform', 'version']);
            $table->index(['platform', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};

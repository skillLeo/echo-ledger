<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Show as (v1.2): a share link can open in the Roman Urdu or English view (null: the original words). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table): void {
            $table->string('view', 16)->nullable()->after('include_audio');
        });
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table): void {
            $table->dropColumn('view');
        });
    }
};

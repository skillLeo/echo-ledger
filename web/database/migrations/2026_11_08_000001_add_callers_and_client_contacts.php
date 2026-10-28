<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caller names (v1.2): who a recording was with (name and number as the device saw them, and where it saw them), and
 * each client's contacts (name, phone, email, language) so a caller is matched to its client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->string('caller_name', 120)->nullable()->after('reference_set_at');
            $table->string('caller_number', 40)->nullable()->after('caller_name');
            $table->string('caller_source', 32)->nullable()->after('caller_number');
        });

        Schema::create('client_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 40)->nullable();
            // The last 10 digits of the phone, so +92 300 1234567, 0300-1234567 and 3001234567 match.
            $table->string('phone_digits', 20)->nullable()->index();
            $table->string('email', 190)->nullable();
            $table->string('language', 16)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn(['caller_name', 'caller_number', 'caller_source']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-up and sign-in with an email code (since v1.6, P1, docs/API.md): the 6-digit codes sent by email (only a keyed
 * hash is kept, 10 minutes, 5 tries) and, once a code of a new address is right, the one-time sign-up token (15
 * minutes, hashed too). Accounts remember whether their owner ever chose a password (an account made with a code or
 * with Google has none to offer) and when they accepted the Terms and the Privacy Policy. Every account made before
 * keeps its password: `has_password` starts true for all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255)->index();
            $table->string('purpose', 16);                 // sign_in | sign_up
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();  // a new address's code was right: the sign-up token is out
            $table->timestamp('used_at')->nullable();
            $table->string('sign_up_token_hash', 64)->nullable()->unique();
            $table->timestamp('sign_up_token_expires_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('has_password')->default(true);
            $table->timestamp('terms_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['has_password', 'terms_accepted_at']);
        });
        Schema::dropIfExists('email_codes');
    }
};

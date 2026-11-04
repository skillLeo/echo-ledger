<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filing rules (v1.5): a recording that ends without a project is filed under the project whose contact it was
 * with, else whose `title` rule its title contains, else whose `app` rule names its app. One rule of a kind and
 * value per workspace. Rollback drops the rules; the contacts that contact rules added stay with their projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            // title | app | contact
            $table->string('kind', 8);
            $table->string('value', 120);
            // What is compared: a title in lower case, an app's wire value, "phone:" + the last 10 digits, "email:" + the address.
            $table->string('match_value', 190);
            // A contact rule adds the contact it names; deleting the rule removes that contact again.
            $table->foreignId('client_contact_id')->nullable()->constrained('client_contacts')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'kind', 'match_value']);
            $table->index(['client_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filing_rules');
    }
};

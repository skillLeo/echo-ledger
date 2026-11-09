<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment receipts (since v1.7, docs/API.md "Payment receipts"): a plan's price in whole rupees, and on each upgrade
 * request a reference code for the bank transfer, the amount due, the customer's transaction ID and payer name, when
 * they said they paid, staff asking for a new receipt and a cancel. The receipts themselves (screenshots or PDFs) are
 * kept encrypted on the recordings disk, one row each in `upgrade_request_proofs`.
 *
 * Prices are seeded only where none is set; existing requests get a reference code. down() drops exactly what up()
 * added, so the seeded prices go with their column.
 */
return new class extends Migration
{
    /** Easy to read aloud: no 0, 1, I, L, O or U. Kept in step with UpgradeRequest::REFERENCE_ALPHABET. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const PRICES = ['pro' => 2999, 'business' => 12499];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('price_pkr')->nullable();
        });
        foreach (self::PRICES as $key => $price) {
            DB::table('plans')->where('key', $key)->whereNull('price_pkr')->update(['price_pkr' => $price]);
        }

        Schema::table('upgrade_requests', function (Blueprint $table): void {
            $table->string('reference_code', 10)->nullable()->unique();
            $table->unsignedInteger('amount_due_pkr')->nullable();
            $table->string('payment_reference', 64)->nullable()->index();
            $table->string('payer_name', 120)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('proof_requested_at')->nullable();
            $table->string('proof_request_note', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
        $taken = [];
        DB::table('upgrade_requests')->whereNull('reference_code')->orderBy('id')->select('id')
            ->chunk(500, function ($rows) use (&$taken): void {
                foreach ($rows as $row) {
                    do {
                        $code = 'EL-';
                        for ($i = 0; $i < 6; $i++) {
                            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                        }
                    } while (isset($taken[$code]));
                    $taken[$code] = true;
                    DB::table('upgrade_requests')->where('id', $row->id)->update(['reference_code' => $code]);
                }
            });

        Schema::create('upgrade_request_proofs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('upgrade_request_id')->constrained('upgrade_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);              // image | pdf
            $table->string('mime', 64);             // as served: image/webp (re-encoded) or application/pdf
            $table->string('name', 255);            // the file's name as the customer sent it
            $table->unsignedInteger('bytes');       // the stored file's size, before encryption
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64)->index();    // of the file as sent
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->timestamp('locked_at')->nullable();  // sent with "paid": can no longer be removed
            $table->timestamp('erased_at')->nullable();  // the files are gone (90 days after the decision); the row stays
            $table->timestamps();
            $table->unique(['upgrade_request_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_request_proofs');
        Schema::table('upgrade_requests', function (Blueprint $table): void {
            $table->dropUnique(['reference_code']);
            $table->dropIndex(['payment_reference']);
        });
        Schema::table('upgrade_requests', function (Blueprint $table): void {
            $table->dropColumn(['reference_code', 'amount_due_pkr', 'payment_reference', 'payer_name', 'paid_at', 'proof_requested_at', 'proof_request_note', 'cancelled_at']);
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('price_pkr');
        });
    }
};

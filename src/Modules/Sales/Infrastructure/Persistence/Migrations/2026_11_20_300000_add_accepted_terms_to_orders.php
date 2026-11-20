<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The terms an order was placed under (flags-2026-11.md S3.3, F16): the store's Terms and Conditions - Content's
| policy and the version of its text the customer ticked - and when, with its Returns and Refunds policy and version
| when it had one. A payment's try keeps the same from Pay and hands it to the order it makes once paid.
|
| Plain values, no foreign key: the policies are Content's, which Sales never reaches (owner, 2026-11-20). Every
| column may be empty - orders and tries made before the tick have none. AcceptedTerms holds the rules (a version of 1
| or more; each policy with its version); no CHECK repeats them (the builder's pick: no new hand-written SQL).
*/

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales.orders', 'sales.checkout_tries'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->ulid('terms_policy_id')->nullable();
                $table->integer('terms_version')->nullable();
                $table->timestampTz('terms_accepted_at')->nullable();
                $table->ulid('returns_policy_id')->nullable();
                $table->integer('returns_version')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sales.orders', 'sales.checkout_tries'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->dropColumn(['terms_policy_id', 'terms_version', 'terms_accepted_at', 'returns_policy_id', 'returns_version']);
            });
        }
    }
};

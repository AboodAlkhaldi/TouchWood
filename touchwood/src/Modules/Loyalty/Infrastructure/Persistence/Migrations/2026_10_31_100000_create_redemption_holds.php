<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Points held while their customer pays online (loyalty.md §9.6, stage 7 step 7): one row per checkout
| try, under the order's future id. A hold moves no lot and writes no ledger entry (owner, 2026-10-29, gap
| 7.3 (a)): the account's free points are its live balance less its holds, and nothing spends, deducts or
| covers with held points (gap 7.2 (a)). An account's lots do not expire while it holds points - the try
| lasts the hold limit at most - so a confirm spends them as they stood when held, and a release lets
| what passed meanwhile expire then, as it would have.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty.redemption_holds', function (Blueprint $table) {
            $table->ulid('order_id');
            $table->ulid('account_id');
            $table->ulid('store_id');
            $table->integer('points');
            $table->bigInteger('discount_minor');
            $table->char('currency_code', 3);
            $table->integer('redemption_cap_percent');
            $table->timestampTz('reserved_at');

            $table->primary('order_id', 'redemption_holds_pkey');
            $table->foreign(['account_id', 'store_id'], 'redemption_holds_account')->references(['id', 'store_id'])->on('loyalty.accounts')->restrictOnDelete();
            $table->index('account_id', 'redemption_holds_by_account');
        });

        DB::statement('ALTER TABLE loyalty.redemption_holds ADD CONSTRAINT redemption_holds_points CHECK (points > 0 AND discount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty.redemption_holds');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Platform spec §5.3. A NULL store_id is a global setting. NULLS NOT DISTINCT makes the unique
| index hold for global rows too: one row per key per store, and one global row per key.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform.settings', function (Blueprint $table) {
            // bigint identity (spec §5), not bigserial.
            $table->id()->generatedAs()->always();
            $table->char('store_id', 26)->nullable();
            $table->string('key', 150);
            $table->jsonb('value');
            $table->char('updated_by', 26)->nullable();
            $table->timestampTz('updated_at');

            $table->foreign('store_id')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX settings_store_key_unique ON platform.settings (store_id, key) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform.settings');
    }
};

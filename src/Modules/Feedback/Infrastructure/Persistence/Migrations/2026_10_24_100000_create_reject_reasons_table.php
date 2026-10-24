<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| feedback.md §2.4 B4 (owner, 2026-10-24): the ready reasons the reject and unpublish dialogs offer — one
| list for every store, the same for reviews and questions, each reason in Arabic and English, in the
| order admins give it.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback.reject_reasons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('text_ar', 500);
            $table->string('text_en', 500);
            $table->integer('position');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index('position', 'reject_reasons_by_position');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE feedback.reject_reasons
                ADD CONSTRAINT reject_reasons_text CHECK (btrim(text_ar) <> '' AND btrim(text_en) <> ''),
                ADD CONSTRAINT reject_reasons_position CHECK (position >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback.reject_reasons');
    }
};

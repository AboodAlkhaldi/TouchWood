<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §1.9: one Brevo list per store and language. Ops makes each list in Brevo the first time a
| contact needs it, in the folder the server names (BREVO_NEWSLETTER_FOLDER_ID), and keeps its id here
| so the same list is used every time after.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.newsletter_lists', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->string('locale', 2);
            $table->bigInteger('brevo_list_id');
            $table->timestampTz('created_at');

            $table->primary(['store_id', 'locale'], 'newsletter_lists_pkey');
            $table->foreign('store_id', 'newsletter_lists_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ops.newsletter_lists ADD CONSTRAINT newsletter_lists_locale CHECK (locale IN ('ar', 'en')), ADD CONSTRAINT newsletter_lists_id CHECK (brevo_list_id > 0)");
        DB::statement('CREATE UNIQUE INDEX newsletter_lists_brevo ON ops.newsletter_lists (brevo_list_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.newsletter_lists');
    }
};

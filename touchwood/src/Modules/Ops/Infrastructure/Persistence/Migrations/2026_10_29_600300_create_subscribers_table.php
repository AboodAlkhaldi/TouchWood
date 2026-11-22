<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §1.9, §5: a store's newsletter list - customers who agreed to offers and news (§1.3), on their
| home store's list only, and guests who gave an email in the shop's box and confirmed it (double
| opt-in). A guest's confirmation token is kept only as its hash; an unconfirmed guest is deleted seven
| days after signing up.
|
| `brevo_email` and `brevo_locale` are what the newsletter provider holds for the row - in the list of
| its store and that language (ops.newsletter_lists) - so a change of email or language moves the
| contact, and a removal knows what to take out. Each CHECK is the last line: the handlers refuse the
| value first.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.subscribers', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->text('email');
            $table->string('locale', 2);
            $table->string('kind', 16);
            $table->ulid('customer_id')->nullable();
            $table->char('token_hash', 64)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('brevo_email')->nullable();
            $table->string('brevo_locale', 2)->nullable();
            $table->timestampTz('brevo_synced_at')->nullable();

            $table->primary('id', 'subscribers_pkey');
            $table->foreign('store_id', 'subscribers_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('customer_id', 'subscribers_customer')->references('id')->on('access.customers')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ops.subscribers
                ADD CONSTRAINT subscribers_email CHECK (char_length(email) BETWEEN 3 AND 254 AND email = lower(email)),
                ADD CONSTRAINT subscribers_locale CHECK (locale IN ('ar', 'en') AND (brevo_locale IS NULL OR brevo_locale IN ('ar', 'en'))),
                ADD CONSTRAINT subscribers_kind CHECK (kind IN ('GUEST', 'CUSTOMER')),
                ADD CONSTRAINT subscribers_customer_kind CHECK ((customer_id IS NOT NULL) = (kind = 'CUSTOMER')),
                ADD CONSTRAINT subscribers_token CHECK (token_hash IS NULL OR (kind = 'GUEST' AND confirmed_at IS NULL AND token_hash ~ '^[0-9a-f]{64}$')),
                ADD CONSTRAINT subscribers_confirmed CHECK (kind = 'GUEST' OR confirmed_at IS NOT NULL),
                ADD CONSTRAINT subscribers_brevo CHECK ((brevo_email IS NULL) = (brevo_locale IS NULL))
            SQL);

        // One row per email in a store, and a customer on one list only: their home store's.
        DB::statement('CREATE UNIQUE INDEX subscribers_store_email ON ops.subscribers (store_id, email)');
        DB::statement('CREATE UNIQUE INDEX subscribers_one_customer ON ops.subscribers (customer_id) WHERE customer_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX subscribers_token_hash ON ops.subscribers (token_hash) WHERE token_hash IS NOT NULL');
        DB::statement('CREATE INDEX subscribers_by_email ON ops.subscribers (email)');
        DB::statement('CREATE INDEX subscribers_unconfirmed ON ops.subscribers (created_at) WHERE confirmed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.subscribers');
    }
};

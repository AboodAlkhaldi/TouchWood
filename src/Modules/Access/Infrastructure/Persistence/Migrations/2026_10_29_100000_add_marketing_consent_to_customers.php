<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amendment 79: whether the customer agreed to offers and news, when that last changed and how.
 * Off for every existing customer; the source and the date are empty until the first change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access.customers
            ADD COLUMN marketing_consent boolean NOT NULL DEFAULT false,
            ADD COLUMN marketing_consent_changed_at timestamptz NULL,
            ADD COLUMN marketing_consent_source varchar(32) NULL');

        DB::statement("ALTER TABLE access.customers ADD CONSTRAINT customers_marketing_consent_source_check
            CHECK (marketing_consent_source IN ('REGISTRATION', 'ACCOUNT', 'UNSUBSCRIBE_LINK', 'NEWSLETTER_PROVIDER'))");

        // A source and its date come together, or neither does.
        DB::statement('ALTER TABLE access.customers ADD CONSTRAINT customers_marketing_consent_change_check
            CHECK ((marketing_consent_source IS NULL) = (marketing_consent_changed_at IS NULL))');

        // Consent on was given by the customer: at registration or in their account, never by an unsubscribe.
        DB::statement("ALTER TABLE access.customers ADD CONSTRAINT customers_marketing_consent_given_check
            CHECK (NOT marketing_consent OR marketing_consent_source IN ('REGISTRATION', 'ACCOUNT'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access.customers
            DROP CONSTRAINT IF EXISTS customers_marketing_consent_given_check,
            DROP CONSTRAINT IF EXISTS customers_marketing_consent_change_check,
            DROP CONSTRAINT IF EXISTS customers_marketing_consent_source_check,
            DROP COLUMN IF EXISTS marketing_consent_source,
            DROP COLUMN IF EXISTS marketing_consent_changed_at,
            DROP COLUMN IF EXISTS marketing_consent');
    }
};

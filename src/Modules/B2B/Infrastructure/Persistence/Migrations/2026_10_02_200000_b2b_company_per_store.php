<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| b2b.md amendments 18 and 19 (owner, 2026-10-02): **a company per store**. An account may hold a
| company in each store it applies in, so:
|
| - `b2b.companies`: one company per account **and store** — `(customer_id, home_store_id)` unique,
|   where `customer_id` alone was;
| - `b2b.applications` gains `store_id`, **the store the application was made in** — a first draft has
|   no company yet, and its store is how "the account's open application in this store" is found.
|   Existing rows take their company's store, or, for a draft with no company yet, the account's home
|   store: until today every company was its account's home store's;
| - one open application per account **and store**.
|
| Each rule is decided in code first, under the account's lock, before these indexes are reached. That
| an application's company is of the application's own store is a rule in code only (the README lists
| B2B's cross-table rules).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE b2b.applications ADD COLUMN store_id char(26)');
        DB::statement(<<<'SQL'
            UPDATE b2b.applications a SET store_id = c.home_store_id
            FROM b2b.companies c WHERE a.company_id = c.id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE b2b.applications a SET store_id = cu.home_store_id
            FROM access.customers cu WHERE a.store_id IS NULL AND a.customer_id = cu.id
            SQL);
        DB::statement('ALTER TABLE b2b.applications ALTER COLUMN store_id SET NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE b2b.applications ADD CONSTRAINT applications_store
                FOREIGN KEY (store_id) REFERENCES platform.stores (id) ON DELETE RESTRICT
            SQL);

        DB::statement('ALTER TABLE b2b.companies DROP CONSTRAINT b2b_companies_customer_id_unique');
        DB::statement('CREATE UNIQUE INDEX companies_one_per_store ON b2b.companies (customer_id, home_store_id)');

        DB::statement('DROP INDEX b2b.applications_one_open_per_customer');
        DB::statement("CREATE UNIQUE INDEX applications_one_open_per_store ON b2b.applications (customer_id, store_id) WHERE state IN ('DRAFT','SUBMITTED')");
    }

    /**
     * Only while no account holds companies in two stores (or two open applications): the old
     * one-per-account keys cannot be put back over data they never allowed. Laravel migrates
     * PostgreSQL in a transaction, so such a rollback fails whole and changes nothing.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS b2b.applications_one_open_per_store');
        DB::statement("CREATE UNIQUE INDEX applications_one_open_per_customer ON b2b.applications (customer_id) WHERE state IN ('DRAFT','SUBMITTED')");
        DB::statement('DROP INDEX IF EXISTS b2b.companies_one_per_store');
        DB::statement('ALTER TABLE b2b.companies ADD CONSTRAINT b2b_companies_customer_id_unique UNIQUE (customer_id)');
        DB::statement('ALTER TABLE b2b.applications DROP CONSTRAINT IF EXISTS applications_store');
        DB::statement('ALTER TABLE b2b.applications DROP COLUMN IF EXISTS store_id');
    }
};

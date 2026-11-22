<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| b2b.md §1.2, §5, §5.2, amendment 14(g): every application is numbered when it is sent —
| TW-CO-26-0001 — the year as its home store's clock reads it, and a count that starts again each
| year, across every store.
|
| The count lives in its own table, one row per year, moved on by the send inside its own
| transaction (DatabaseApplicationReferenceCounter), so a year's numbers have no gaps.
|
| The applications already sent are numbered here, oldest first, each in the year its home store
| saw it sent in — so the CHECK that every sent application has a number holds from this migration
| on. Nothing is live yet (owner, 2026-09-30): these are local and test rows.
|
| ApplicationReference and Application::reconstitute refuse the same shapes first; these are the
| backstop, as for the module's other one-table rules.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b.application_reference_counters', function (Blueprint $table): void {
            $table->integer('year');
            $table->integer('last_number');
        });

        // Named here: Laravel's primary() takes a name that PostgreSQL's grammar then ignores.
        DB::statement('ALTER TABLE b2b.application_reference_counters ADD CONSTRAINT application_reference_counters_pkey PRIMARY KEY (year)');
        DB::statement('ALTER TABLE b2b.application_reference_counters ADD CONSTRAINT application_reference_counters_last_number CHECK (last_number >= 1)');

        Schema::table('b2b.applications', function (Blueprint $table): void {
            $table->string('reference', 32)->nullable();
        });

        DB::statement(<<<'SQL'
            WITH sent AS (
                SELECT a.id,
                       a.submitted_at,
                       extract(year FROM a.submitted_at AT TIME ZONE s.timezone)::int AS year
                FROM b2b.applications a
                JOIN b2b.companies c ON c.id = a.company_id
                JOIN platform.stores s ON s.id = c.home_store_id
                WHERE a.state <> 'DRAFT'
            ),
            numbered AS (
                SELECT id, year, row_number() OVER (PARTITION BY year ORDER BY submitted_at, id) AS number
                FROM sent
            )
            UPDATE b2b.applications a
            SET reference = 'TW-CO-' || lpad((numbered.year - 2000)::text, 2, '0') || '-' || lpad(numbered.number::text, 4, '0')
            FROM numbered
            WHERE a.id = numbered.id
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO b2b.application_reference_counters (year, last_number)
            SELECT 2000 + substring(reference FROM 7 FOR 2)::int, max(substring(reference FROM 10)::int)
            FROM b2b.applications
            WHERE reference IS NOT NULL
            GROUP BY 1
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE b2b.applications ADD CONSTRAINT applications_reference_format CHECK (
                reference IS NULL OR (reference ~ '^TW-CO-[0-9]{2}-[0-9]{4,}$' AND substring(reference FROM 10)::bigint >= 1)
            )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE b2b.applications ADD CONSTRAINT applications_reference_when_sent CHECK (
                (state = 'DRAFT') = (reference IS NULL)
            )
            SQL);

        DB::statement('CREATE UNIQUE INDEX applications_reference_unique ON b2b.applications (reference)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS b2b.applications_reference_unique');
        DB::statement('ALTER TABLE b2b.applications DROP CONSTRAINT IF EXISTS applications_reference_when_sent');
        DB::statement('ALTER TABLE b2b.applications DROP CONSTRAINT IF EXISTS applications_reference_format');

        Schema::table('b2b.applications', function (Blueprint $table): void {
            $table->dropColumn('reference');
        });

        Schema::dropIfExists('b2b.application_reference_counters');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| b2b.md §5, amendment 13(d): a company is never approved as "Other". Company::approve refuses it
| first (CompanyTypeNotSet), and Company::correctType never makes an approved company "Other"; this is
| the backstop, as for the module's other one-table rules.
|
| A company suspended from approved counts as approved: reinstating returns it there, and its type
| cannot change while it is suspended (amendment 10(h)).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE b2b.companies ADD CONSTRAINT companies_approved_type_listed CHECK (
                (status <> 'APPROVED' AND status_before_suspension IS DISTINCT FROM 'APPROVED')
                OR company_type_id IS NOT NULL
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE b2b.companies DROP CONSTRAINT IF EXISTS companies_approved_type_listed');
    }
};

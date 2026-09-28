<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| b2b.md §5: the company, its applications, and the files each application carries.
|
| Every rule here is also a rule in code — the value objects, and the Company and Application
| aggregates — which refuses first; these are the backstop. Two have their code half in the company's
| own use cases (step 3b): one open application per account and one company per account, both decided
| under the account's lock (ApplicationRepository::lockAccount) before these indexes are reached.
*/

return new class extends Migration
{
    private const string STATUSES = "'PENDING','APPROVED','REJECTED','SUSPENDED'";

    private const string STATES = "'DRAFT','SUBMITTED','APPROVED','REJECTED'";

    public function up(): void
    {
        Schema::create('b2b.companies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id')->unique();
            $table->string('name', 200);
            $table->ulid('company_type_id')->nullable();
            $table->string('company_type_other', 100)->nullable();
            $table->string('cr_number', 50);
            $table->string('tax_number', 50);
            $table->string('address', 500);
            $table->ulid('home_store_id');
            $table->string('status', 16);
            $table->string('status_before_suspension', 16)->nullable();
            $table->string('status_reason', 1000)->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->ulid('status_changed_by')->nullable();
            $table->timestampsTz();

            $table->foreign('customer_id')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('company_type_id')->references('id')->on('b2b.company_types')->restrictOnDelete();
            $table->foreign('home_store_id')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('status_changed_by')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->index(['home_store_id', 'status']);
            $table->index('status');
        });

        Schema::create('b2b.applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id');
            $table->ulid('company_id')->nullable();
            $table->string('state', 16);
            // The snapshot: each may be empty while it is a draft (b2b.md §1.2).
            $table->string('name', 200)->nullable();
            $table->ulid('company_type_id')->nullable();
            $table->string('company_type_other', 100)->nullable();
            $table->string('cr_number', 50)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->timestampsTz();

            $table->foreign('customer_id')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('b2b.companies')->restrictOnDelete();
            $table->foreign('company_type_id')->references('id')->on('b2b.company_types')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->index(['customer_id', 'state']);
        });

        Schema::create('b2b.application_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('application_id');
            $table->ulid('document_type_id');
            $table->ulid('media_id');
            $table->timestampTz('uploaded_at');

            $table->foreign('application_id')->references('id')->on('b2b.applications')->cascadeOnDelete();
            $table->foreign('document_type_id')->references('id')->on('b2b.document_types')->restrictOnDelete();
            // A file an application holds is never deleted from under it (b2b.md §1.4).
            $table->foreign('media_id')->references('id')->on('platform.media')->restrictOnDelete();
            // One file per type (owner, 2026-09-27). Its leading column also serves "the documents
            // of one application". Named, because Laravel's name for it is longer than the 63
            // characters PostgreSQL keeps, and a truncated name matches nothing anybody searches for.
            $table->unique(['application_id', 'document_type_id'], 'application_documents_one_per_type');
        });

        $this->companyRules();
        $this->applicationRules();

        // "Has this account an open application?" is answered here, where two tabs cannot both win
        // (b2b.md §5.2); and one company's history, newest first.
        DB::statement("CREATE UNIQUE INDEX applications_one_open_per_customer ON b2b.applications (customer_id) WHERE state IN ('DRAFT','SUBMITTED')");
        DB::statement('CREATE INDEX applications_company_history ON b2b.applications (company_id, submitted_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b.application_documents');
        Schema::dropIfExists('b2b.applications');
        Schema::dropIfExists('b2b.companies');
    }

    private function companyRules(): void
    {
        $rules = [
            'status' => 'status IN ('.self::STATUSES.')',
            // Suspended remembers where it came from, and only suspended does (b2b.md §4.1).
            // COALESCE: a CHECK that comes out NULL passes, and the column is NULL most of the time.
            'status_before_suspension' => "(status = 'SUSPENDED') = (status_before_suspension IS NOT NULL) AND COALESCE(status_before_suspension IN ('PENDING','APPROVED','REJECTED'), true)",
            // Rejected and suspended say why; the customer is shown it (b2b.md §1.1).
            'status_reason_given' => "status NOT IN ('REJECTED','SUSPENDED') OR status_reason IS NOT NULL",
            'type_exactly_one' => 'num_nonnulls(company_type_id, company_type_other) = 1',
            ...self::textRules(),
            'status_reason_text' => self::lines('status_reason'),
        ];

        foreach ($rules as $name => $rule) {
            DB::statement("ALTER TABLE b2b.companies ADD CONSTRAINT companies_{$name} CHECK ({$rule})");
        }
    }

    private function applicationRules(): void
    {
        $sent = "state = 'DRAFT' OR (company_id IS NOT NULL AND submitted_at IS NOT NULL AND name IS NOT NULL AND cr_number IS NOT NULL AND tax_number IS NOT NULL AND address IS NOT NULL AND num_nonnulls(company_type_id, company_type_other) = 1)";

        $rules = [
            'state' => 'state IN ('.self::STATES.')',
            // Everything is there once it is sent; a draft may hold at most one kind of type.
            'sent_complete' => $sent,
            'type_at_most_one' => 'num_nonnulls(company_type_id, company_type_other) <= 1',
            // Decided means decided by somebody, when; a rejection says why (b2b.md §1.2).
            'decided_together' => "(state IN ('APPROVED','REJECTED')) = (decided_at IS NOT NULL AND decided_by IS NOT NULL)",
            'rejection_reason_given' => "state <> 'REJECTED' OR decision_reason IS NOT NULL",
            ...self::textRules(),
            'note_text' => self::lines('note'),
            'decision_reason_text' => self::lines('decision_reason'),
        ];

        foreach ($rules as $name => $rule) {
            DB::statement("ALTER TABLE b2b.applications ADD CONSTRAINT applications_{$name} CHECK ({$rule})");
        }
    }

    /**
     * The company's typed values, which both tables hold — each checked only when present, since a
     * draft's may be empty.
     *
     * @return array<string, string>
     */
    private static function textRules(): array
    {
        return [
            'name_text' => self::oneLine('name'),
            'type_other_text' => self::oneLine('company_type_other'),
            'cr_number_text' => self::number('cr_number'),
            'tax_number_text' => self::number('tax_number'),
            'address_text' => self::lines('address'),
        ];
    }

    private static function oneLine(string $column): string
    {
        return "{$column} IS NULL OR (btrim({$column}) <> '' AND {$column} !~ '[[:cntrl:]]')";
    }

    /**
     * A line break is allowed; no other character below the space is. The escapes are written for
     * PostgreSQL's regex, not PHP: single quotes keep the backslashes as they are.
     */
    private static function lines(string $column): string
    {
        return $column.' IS NULL OR (btrim('.$column.') <> \'\' AND '.$column.' !~ \'[\x01-\x09\x0B-\x1F\x7F]\')';
    }

    /** Letters, digits, spaces and dashes (b2b.md amendment 2). */
    private static function number(string $column): string
    {
        return "{$column} IS NULL OR {$column} ~ '^[[:alnum:] -]+$'";
    }
};

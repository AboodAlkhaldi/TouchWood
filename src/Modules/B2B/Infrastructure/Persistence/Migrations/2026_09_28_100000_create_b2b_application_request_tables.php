<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| b2b.md §1.2, §5 (amendment 4): what a rejection says to fix and what to add, and the next draft's
| answers. Flags and requests belong to the rejected application; each answer belongs to the
| application that gives it.
|
| Every rule here is also a rule in code — Application::reject(), answer() and submit(), and the
| value objects — which refuses first; these are the backstop. Every constraint is named, and every
| name is well inside the 63 characters PostgreSQL keeps.
|
| Five rules cross two tables and are code-only, with nothing here behind them (amendments 5(g) and
| 6(c)): an answer matches its request's kind; flags and requests exist only on a rejected
| application; an answer's request belongs to the last rejection; a company's type comes from its
| home store's list; staff flag only a document the rejected application sent a file under.
|
| Until step 4's RejectCompany, nothing but the tests writes flags or requests; the answers arrive
| with the company's side in step 3b.
*/

return new class extends Migration
{
    private const string FIELDS = "'name','company_type','cr_number','tax_number','address'";

    public function up(): void
    {
        Schema::create('b2b.application_flags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The rejected application: the flag is part of what it was told.
            $table->ulid('application_id');
            $table->string('field', 16)->nullable();
            $table->ulid('document_type_id')->nullable();

            $table->foreign('application_id', 'application_flags_application_id_foreign')->references('id')->on('b2b.applications')->cascadeOnDelete();
            $table->foreign('document_type_id', 'application_flags_document_type_id_foreign')->references('id')->on('b2b.document_types')->restrictOnDelete();
        });

        Schema::create('b2b.application_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The rejected application, as for a flag.
            $table->ulid('application_id');
            $table->string('kind', 8);
            // The staff member's words: one line, at most 200 characters.
            $table->string('label', 200);
            $table->integer('position');

            $table->foreign('application_id', 'application_requests_application_id_foreign')->references('id')->on('b2b.applications')->cascadeOnDelete();
        });

        Schema::create('b2b.application_request_answers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The answering application: an answer is one of the values it sends.
            $table->ulid('application_id');
            $table->ulid('request_id');
            $table->string('text', 1000)->nullable();
            $table->ulid('media_id')->nullable();

            $table->foreign('application_id', 'application_request_answers_application_id_foreign')->references('id')->on('b2b.applications')->cascadeOnDelete();
            // A request an answer points at is never deleted from under it.
            $table->foreign('request_id', 'application_request_answers_request_id_foreign')->references('id')->on('b2b.application_requests')->restrictOnDelete();
            // A file an application holds is never deleted from under it (b2b.md §1.4).
            $table->foreign('media_id', 'application_request_answers_media_id_foreign')->references('id')->on('platform.media')->restrictOnDelete();
            // One answer per request per application; its leading column also serves "the answers
            // of one application".
            $table->unique(['application_id', 'request_id'], 'application_request_answers_one_per_request');
        });

        $rules = [
            'application_flags' => [
                'application_flags_field' => 'field IS NULL OR field IN ('.self::FIELDS.')',
                // A field, or a document type: exactly one.
                'application_flags_one_item' => 'num_nonnulls(field, document_type_id) = 1',
            ],
            'application_requests' => [
                'application_requests_kind' => "kind IN ('TEXT','FILE')",
                'application_requests_label_text' => self::oneLine('label'),
                'application_requests_position_range' => 'position BETWEEN 0 AND 10000',
            ],
            'application_request_answers' => [
                // Text or a file: exactly one. Which one its request asks for is code-only.
                'application_request_answers_one_value' => 'num_nonnulls(text, media_id) = 1',
                'application_request_answers_text_text' => self::lines('text'),
            ],
        ];

        foreach ($rules as $table => $checks) {
            foreach ($checks as $name => $rule) {
                DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
            }
        }

        // One flag per field, and one per document type, per application (b2b.md §5).
        DB::statement('CREATE UNIQUE INDEX application_flags_one_per_field ON b2b.application_flags (application_id, field) WHERE field IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX application_flags_one_per_document ON b2b.application_flags (application_id, document_type_id) WHERE document_type_id IS NOT NULL');

        // "Which applications hold this file?" — asked by B2B's MediaUsage before every delete.
        DB::statement('CREATE INDEX application_documents_media ON b2b.application_documents (media_id)');
        DB::statement('CREATE INDEX application_request_answers_media ON b2b.application_request_answers (media_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS b2b.application_documents_media');
        Schema::dropIfExists('b2b.application_request_answers');
        Schema::dropIfExists('b2b.application_requests');
        Schema::dropIfExists('b2b.application_flags');
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
};

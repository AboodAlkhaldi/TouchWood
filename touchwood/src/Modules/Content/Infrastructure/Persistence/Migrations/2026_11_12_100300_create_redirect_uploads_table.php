<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.15, §5, W11b: a redirect sheet read and checked, line by line - New, Same as Now, Change, Refused and
| why - waiting for Apply Sheet, which alone writes it. Dropped after 24 hours (§9.3 #31).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.redirect_uploads', function (Blueprint $table) {
            $table->ulid('id');
            $table->jsonb('lines');
            $table->string('state', 8);
            $table->ulid('created_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('applied_at')->nullable();

            $table->primary('id', 'redirect_uploads_pkey');
            $table->index('created_at', 'redirect_uploads_created_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.redirect_uploads
                ADD CONSTRAINT redirect_uploads_lines CHECK (COALESCE(jsonb_typeof(lines) = 'array', false)),
                ADD CONSTRAINT redirect_uploads_state CHECK (state IN ('CHECKED', 'APPLIED')),
                ADD CONSTRAINT redirect_uploads_applied CHECK ((state = 'APPLIED') = (applied_at IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.redirect_uploads');
    }
};

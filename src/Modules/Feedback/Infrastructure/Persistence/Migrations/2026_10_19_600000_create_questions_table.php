<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| feedback.md §5, the questions' part: asked in a store by a customer, answered — or rejected — by that
| store's staff, global once answered (answer 16), unpublished from the shop once shown (answer 18).
| Every rule the code checks first is held here too (handoff §5.3).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback.questions', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('product_id');
            $table->ulid('store_id');
            $table->ulid('customer_id');
            $table->text('body');
            $table->string('locale', 2);
            $table->string('status', 12);
            $table->text('answer')->nullable();
            $table->ulid('answered_by')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'questions_pkey');
            $table->foreign('product_id', 'questions_product')->references('id')->on('catalog.products')->restrictOnDelete();
            $table->foreign('store_id', 'questions_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('customer_id', 'questions_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('answered_by', 'questions_answered_by')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE feedback.questions
                ADD CONSTRAINT questions_body CHECK (char_length(body) BETWEEN 1 AND 1000),
                ADD CONSTRAINT questions_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT questions_status CHECK (status IN ('PENDING', 'ANSWERED', 'REJECTED', 'UNPUBLISHED')),
                ADD CONSTRAINT questions_answered CHECK (num_nonnulls(answer, answered_by, answered_at) IN (0, 3)),
                ADD CONSTRAINT questions_answer_status CHECK ((status IN ('ANSWERED', 'UNPUBLISHED')) = (answer IS NOT NULL)),
                ADD CONSTRAINT questions_answer CHECK (answer IS NULL OR char_length(answer) BETWEEN 1 AND 2000),
                ADD CONSTRAINT questions_reason CHECK (reason IS NULL OR (status IN ('REJECTED', 'UNPUBLISHED') AND char_length(reason) BETWEEN 1 AND 500))
            SQL);

        DB::statement('CREATE INDEX questions_by_product ON feedback.questions (product_id, status, answered_at DESC, id)');
        DB::statement('CREATE INDEX questions_by_store_queue ON feedback.questions (store_id, status, created_at, id)');
        DB::statement('CREATE INDEX questions_by_customer ON feedback.questions (customer_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback.questions');
    }
};

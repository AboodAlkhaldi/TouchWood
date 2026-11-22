<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| feedback.md §5: a product's page reads approved reviews by (created_at DESC, id DESC) and answered
| questions by (answered_at DESC, id DESC) (step F6b); the indexes now end the same way, so a tie
| needs no sort of its own (the module pass's review, 2026-10-19).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX feedback.reviews_by_product');
        DB::statement('CREATE INDEX reviews_by_product ON feedback.reviews (product_id, status, created_at DESC, id DESC)');
        DB::statement('DROP INDEX feedback.questions_by_product');
        DB::statement('CREATE INDEX questions_by_product ON feedback.questions (product_id, status, answered_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX feedback.reviews_by_product');
        DB::statement('CREATE INDEX reviews_by_product ON feedback.reviews (product_id, status, created_at DESC, id)');
        DB::statement('DROP INDEX feedback.questions_by_product');
        DB::statement('CREATE INDEX questions_by_product ON feedback.questions (product_id, status, answered_at DESC, id)');
    }
};

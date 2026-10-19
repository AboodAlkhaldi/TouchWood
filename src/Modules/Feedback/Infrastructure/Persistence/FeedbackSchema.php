<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * The feedback schema itself (feedback.md §5), as the first migration creates and drops it — kept
 * here, as Promotions' PromotionsSchema is, so the statements have one home that a test can run from
 * nothing.
 *
 * The schema is on config/database.php's search_path, so a fresh migration wipes it with the rest.
 */
final class FeedbackSchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS feedback');
    }

    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS feedback CASCADE');
    }
}

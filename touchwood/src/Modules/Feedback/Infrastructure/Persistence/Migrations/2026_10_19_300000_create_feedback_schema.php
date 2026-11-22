<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Feedback\Infrastructure\Persistence\FeedbackSchema;

/*
| feedback.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes it.
*/

return new class extends Migration
{
    public function up(): void
    {
        FeedbackSchema::create();
    }

    public function down(): void
    {
        FeedbackSchema::drop();
    }
};

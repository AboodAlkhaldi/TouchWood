<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.1 rule 10: Platform asks where a file is used before deleting it (ContentMediaUsage), and the RESTRICT
| keys are checked on every media delete: the share images, a post's cover and their keys get the indexes the link
| tables already have (PR 3 review).
*/

return new class extends Migration
{
    /** @var array<string, list<string>> table => its media columns */
    private const array COLUMNS = [
        'pages' => ['share_media_ar', 'share_media_en'],
        'blog_posts' => ['cover_media_id', 'share_media_ar', 'share_media_en'],
        'projects' => ['share_media_ar', 'share_media_en'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table("content.{$table}", function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    $blueprint->index($column, "{$table}_{$column}_idx");
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table("content.{$table}", function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex("{$table}_{$column}_idx");
                }
            });
        }
    }
};

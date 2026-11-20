<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| flags-2026-11.md S3.1: a policy's changed address forwards as a page's does, so its addresses are kept in
| content.slug_history under the kind POLICY (one row per store the policy served with it); and Translate's unread
| English is marked on a policy as on a page (content.md §1.3 #4), in content.unread_translations. Both kind CHECKs
| gain POLICY.
|
| CHECKs the Schema builder cannot write, in standard SQL (CONVENTIONS, "Database"); the code names only these kinds
| first (`PolicyWriter::KIND`).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE content.slug_history DROP CONSTRAINT slug_history_kind');
        DB::statement("ALTER TABLE content.slug_history ADD CONSTRAINT slug_history_kind CHECK (kind IN ('PAGE', 'POST', 'PROJECT', 'BLOG_CATEGORY', 'CAMPAIGN', 'POLICY'))");
        DB::statement('ALTER TABLE content.unread_translations DROP CONSTRAINT unread_translations_kind');
        DB::statement(<<<'SQL'
            ALTER TABLE content.unread_translations
                ADD CONSTRAINT unread_translations_kind CHECK (kind IN ('PAGE', 'POST', 'PROJECT', 'BANNER', 'POPUP', 'HOME_SECTION', 'BAR', 'CAMPAIGN', 'THEME', 'POLICY'))
            SQL);
    }

    public function down(): void
    {
        DB::table('content.slug_history')->where('kind', 'POLICY')->delete();
        DB::table('content.unread_translations')->where('kind', 'POLICY')->delete();
        DB::statement('ALTER TABLE content.slug_history DROP CONSTRAINT slug_history_kind');
        DB::statement("ALTER TABLE content.slug_history ADD CONSTRAINT slug_history_kind CHECK (kind IN ('PAGE', 'POST', 'PROJECT', 'BLOG_CATEGORY', 'CAMPAIGN'))");
        DB::statement('ALTER TABLE content.unread_translations DROP CONSTRAINT unread_translations_kind');
        DB::statement(<<<'SQL'
            ALTER TABLE content.unread_translations
                ADD CONSTRAINT unread_translations_kind CHECK (kind IN ('PAGE', 'POST', 'PROJECT', 'BANNER', 'POPUP', 'HOME_SECTION', 'BAR', 'CAMPAIGN', 'THEME'))
            SQL);
    }
};

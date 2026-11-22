<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| content.md §1.3 #4 (the owner's P14, 2026-11-11): "Campaigns and themes (PR 8) follow the same rule at Schedule." A
| campaign's name and a theme's words Translate filled, saved with nobody ticking "I read the translated English.",
| keep their mark until the tick (`TranslationMarks`), as the other items do. The CHECK on the kinds gains the two.
|
| A CHECK the Schema builder cannot write, in standard SQL (CONVENTIONS, "Database"); the code names only these kinds
| first (`UnreadTranslations`).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE content.unread_translations DROP CONSTRAINT unread_translations_kind');
        DB::statement(<<<'SQL'
            ALTER TABLE content.unread_translations
                ADD CONSTRAINT unread_translations_kind CHECK (kind IN ('PAGE', 'POST', 'PROJECT', 'BANNER', 'POPUP', 'HOME_SECTION', 'BAR', 'CAMPAIGN', 'THEME'))
            SQL);
    }

    public function down(): void
    {
        DB::table('content.unread_translations')->whereIn('kind', ['CAMPAIGN', 'THEME'])->delete();
        DB::statement('ALTER TABLE content.unread_translations DROP CONSTRAINT unread_translations_kind');
        DB::statement(<<<'SQL'
            ALTER TABLE content.unread_translations
                ADD CONSTRAINT unread_translations_kind CHECK (kind IN ('PAGE', 'POST', 'PROJECT', 'BANNER', 'POPUP', 'HOME_SECTION', 'BAR'))
            SQL);
    }
};

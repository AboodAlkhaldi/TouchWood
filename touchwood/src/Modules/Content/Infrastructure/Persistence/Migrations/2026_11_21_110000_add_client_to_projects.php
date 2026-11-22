<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| flags-2026-11.md S4.1, content.md §1.13 (amendment 3): who a project was made with, all optional. The client's name is
| per language (100 characters, as the city); the logo is one of the media library's, held by a RESTRICT key as every
| Content image is (the 100 characters are the column's own, as the city's); the link is an https:// address and is the same in both languages.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content.projects', function (Blueprint $table) {
            $table->ulid('client_logo_media')->nullable();
            $table->text('client_link')->nullable();

            $table->foreign('client_logo_media', 'projects_client_logo')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('client_logo_media', 'projects_client_logo_idx');
        });

        Schema::table('content.project_translations', function (Blueprint $table) {
            $table->string('client_name', 100)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.projects
                ADD CONSTRAINT projects_client_link CHECK (client_link IS NULL OR (client_link ~ '^https://[^/?#[:space:]\\]+' AND char_length(client_link) <= 2000 AND client_link !~ '[[:space:]\\]'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE content.project_translations
                ADD CONSTRAINT prt_client_name CHECK (client_name IS NULL OR char_length(btrim(client_name)) >= 1)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE content.project_translations DROP CONSTRAINT prt_client_name');
        DB::statement('ALTER TABLE content.projects DROP CONSTRAINT projects_client_link');

        Schema::table('content.project_translations', function (Blueprint $table) {
            $table->dropColumn('client_name');
        });

        Schema::table('content.projects', function (Blueprint $table) {
            $table->dropIndex('projects_client_logo_idx');
            $table->dropForeign('projects_client_logo');
            $table->dropColumn(['client_logo_media', 'client_link']);
        });
    }
};

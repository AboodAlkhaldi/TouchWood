<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| promotions.md §5's index for the staff screens' segment list (§3): a store's segments newest first,
| the keyset a page walks.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX segments_by_store_newest ON promotions.segments (store_id, created_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS promotions.segments_by_store_newest');
    }
};

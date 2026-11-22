<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| One phone per customer (access.md amendment 89): an address keeps no phone of its own; delivery
| uses the account's verified phone. The column and its check go; every address stays as it was.
|
| Going back brings the column back empty and nullable: the numbers dropped are not kept anywhere.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access.addresses DROP CONSTRAINT addresses_phone_format');

        Schema::table('access.addresses', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }

    public function down(): void
    {
        Schema::table('access.addresses', function (Blueprint $table) {
            $table->string('phone', 16)->nullable();
        });

        DB::statement("ALTER TABLE access.addresses ADD CONSTRAINT addresses_phone_format CHECK (phone ~ '^\\+[1-9][0-9]{6,14}$')");
    }
};

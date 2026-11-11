<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §9.6 #1 (owner, Content round 3 #17 and the answer (b) of 2026-11-10): the nightly read of Brevo's
| unsubscribes keeps, for each list, the start of its last read that reached the list's end. The next read asks for
| the contacts changed since an hour before it. None yet: the first read starts an hour before the list was made.
| It goes with the list's row: a list forgotten (gone at Brevo) starts again with the new one.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops.newsletter_lists', function (Blueprint $table) {
            $table->timestampTz('unsubscribes_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ops.newsletter_lists', function (Blueprint $table) {
            $table->dropColumn('unsubscribes_read_at');
        });
    }
};

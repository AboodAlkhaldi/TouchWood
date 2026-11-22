<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §5, §6: every Ops listener runs once per event - the outbox delivers at least once. The
| event's id and the listener's name are kept under one key, written in the same transaction as the
| listener's work (HandledEvents). One event may have two listeners (OrderPlaced: C1 and S1), hence
| the listener in the key. Rows older than twelve months are deleted (§5).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.handled_events', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->string('listener', 255);
            $table->timestampTz('handled_at');

            $table->primary(['event_id', 'listener'], 'handled_events_pkey');
        });

        DB::statement('ALTER TABLE ops.handled_events ADD CONSTRAINT handled_events_listener CHECK (char_length(listener) BETWEEN 1 AND 255)');
        DB::statement('CREATE INDEX handled_events_by_age ON ops.handled_events (handled_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.handled_events');
    }
};

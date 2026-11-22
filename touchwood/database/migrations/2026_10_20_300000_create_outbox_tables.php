<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The event plumbing every module uses (platform.md §5.6; sales.md §5): the transactional outbox for the
| critical events and the idempotent consumers' record, in the public schema — Shared/Infrastructure's,
| built with Sales, the first module to publish through it.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public.outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->string('type', 255);
            $table->jsonb('payload');
            $table->timestampTz('created_at');
            $table->timestampTz('available_at');
            $table->integer('attempts');
            $table->text('last_error')->nullable();
            $table->timestampTz('dispatched_at')->nullable();

            $table->unique('event_id', 'outbox_messages_event_id');
        });

        DB::statement('ALTER TABLE public.outbox_messages ADD CONSTRAINT outbox_messages_attempts CHECK (attempts >= 0)');
        // The relay's walk: what is waiting, in the order written.
        DB::statement('CREATE INDEX outbox_messages_waiting ON public.outbox_messages (available_at, id) WHERE dispatched_at IS NULL');
        // The pruning of what was sent 30 days ago, run with every relay.
        DB::statement('CREATE INDEX outbox_messages_sent ON public.outbox_messages (dispatched_at) WHERE dispatched_at IS NOT NULL');

        Schema::create('public.processed_events', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->string('listener', 255);
            $table->timestampTz('processed_at');

            $table->primary(['event_id', 'listener'], 'processed_events_pkey');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public.processed_events');
        Schema::dropIfExists('public.outbox_messages');
    }
};

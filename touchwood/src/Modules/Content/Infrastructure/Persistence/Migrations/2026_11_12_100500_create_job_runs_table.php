<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Content's long jobs that finished, by the queued job's uuid (PR 4 review; `RunOnce`): the database queue hands a
| job still running to a second worker once its `retry_after` passes, and that copy carries the same uuid. A copy of a
| job running now finds its lock held; a copy of one that finished finds its row here; either deletes itself instead of
| running the work again. Not one of content.md §5's first tables: the build's pick, written into §5.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.job_runs', function (Blueprint $table) {
            $table->uuid('uuid');
            $table->timestampTz('finished_at');

            $table->primary('uuid', 'job_runs_pkey');
            $table->index('finished_at', 'job_runs_finished_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content.job_runs');
    }
};

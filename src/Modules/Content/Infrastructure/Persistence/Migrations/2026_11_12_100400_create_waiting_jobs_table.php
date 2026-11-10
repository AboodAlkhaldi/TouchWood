<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Content's queued work asked for (content.md W10's Rebuild Sitemap, W11's Check Addresses, §6): one row a job.
| `queued_at`: one is queued and waits - the button is disabled while it does, and a second press queues nothing.
| For the sitemaps: `wanted_at`, a change since the last build wants another (a store switched on or off:
| `wanted_now`; Catalog's changes: at most one such build a day beyond the nightly, `extra_on` its day, §9.3 #35),
| taken up by a tick every ten minutes - so no change in another module builds anything in its own request.
| Not one of §5's tables: the build's pick (plan-content.md PR 4), as Inventory's `recount_queued`.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.waiting_jobs', function (Blueprint $table) {
            $table->string('job', 32);
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('wanted_at')->nullable();
            $table->boolean('wanted_now')->default(false);
            $table->date('extra_on')->nullable();

            $table->primary('job', 'waiting_jobs_pkey');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.waiting_jobs
                ADD CONSTRAINT waiting_jobs_job CHECK (job IN ('BUILD_SITEMAPS', 'CHECK_REDIRECTS')),
                ADD CONSTRAINT waiting_jobs_wanted CHECK (NOT wanted_now OR wanted_at IS NOT NULL)
            SQL);

        DB::table('content.waiting_jobs')->insert([['job' => 'BUILD_SITEMAPS'], ['job' => 'CHECK_REDIRECTS']]);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.waiting_jobs');
    }
};

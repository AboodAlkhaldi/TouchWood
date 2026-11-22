<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.7, §5: a campaign - a named period with prices, separate from a theme - and its **runs**, one per store
| it picks, each with its own id (the one Ops' events carry), dates, prices, notice and state.
|
| - The campaign keeps its start and end **as typed** (`*_local`, no zone); each run keeps its store's own moments
|   (Q1 (a)). A run's state is stored (§4.1): `LIVE` is written by the job's conditional update alone (§1.7 #9).
| - `price_choices`: a draft's choices as typed (ids and amounts, no media); Pricing holds them from Schedule on
|   (§1.7 #14), so only a draft has them.
| - `notice_id` is Ops' notice, with no key; **one notice serves one run at a time** while the run may still send it
|   (a partial unique index over DRAFT and SCHEDULED runs, §1.7 #10).
| - `warned_72_at`, `warned_24_at`: the two ending alerts' stamps (§1.7 #11), set by conditional updates.
| - `campaign_run_targets`: the categories and brands a run not yet ended or cancelled names, each with its RESTRICT
|   key, so Catalog's delete of one in use is refused (Content's `CatalogUsage`, §1.1 rule 17). A draft's come from its
|   choices, a scheduled or live one's from what was scheduled; they go when the run ends or is cancelled.
| - `smart_bars.run_id` gains its key: a campaign's smart bar goes with its run.
|
| Every CHECK on a nullable column is written so a NULL cannot slip through (lessons 35, 162): each compares with
| `IS NULL` / `IS NOT NULL` or is guarded by it.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.campaigns', function (Blueprint $table) {
            $table->ulid('id');
            $table->string('name_ar', 100);
            $table->string('name_en', 100)->nullable();
            $table->timestamp('starts_at_local', 0);
            $table->timestamp('ends_at_local', 0);
            $table->ulid('theme_id')->nullable();
            $table->integer('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'campaigns_pkey');
            $table->foreign('theme_id', 'campaigns_theme')->references('id')->on('content.themes')->restrictOnDelete();
            $table->index('theme_id', 'campaigns_theme_idx');
            $table->index(['created_at', 'id'], 'campaigns_by_age');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.campaigns
                ADD CONSTRAINT campaigns_name CHECK (char_length(btrim(name_ar)) >= 1 AND (name_en IS NULL OR char_length(btrim(name_en)) >= 1)),
                ADD CONSTRAINT campaigns_dates CHECK (ends_at_local > starts_at_local),
                ADD CONSTRAINT campaigns_version CHECK (version >= 1)
            SQL);

        Schema::create('content.campaign_runs', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('campaign_id');
            $table->ulid('store_id');
            $table->string('state', 16);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->jsonb('price_choices')->nullable();
            $table->string('slug_ar', 200)->nullable();
            $table->string('slug_en', 200)->nullable();
            $table->ulid('notice_id')->nullable();
            $table->timestampTz('went_live_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('warned_72_at')->nullable();
            $table->timestampTz('warned_24_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'campaign_runs_pkey');
            $table->foreign('campaign_id', 'campaign_runs_campaign')->references('id')->on('content.campaigns')->cascadeOnDelete();
            $table->foreign('store_id', 'campaign_runs_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['campaign_id', 'store_id'], 'campaign_runs_one_per_store');
            $table->index(['state', 'starts_at'], 'campaign_runs_by_start');
            $table->index(['state', 'ends_at'], 'campaign_runs_by_end');
            $table->index('store_id', 'campaign_runs_store_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.campaign_runs
                ADD CONSTRAINT campaign_runs_state CHECK (state IN ('DRAFT', 'SCHEDULED', 'LIVE', 'ENDED', 'CANCELLED')),
                ADD CONSTRAINT campaign_runs_dates CHECK (ends_at > starts_at),
                ADD CONSTRAINT campaign_runs_choices CHECK (price_choices IS NULL OR (state = 'DRAFT' AND jsonb_typeof(price_choices) = 'object')),
                ADD CONSTRAINT campaign_runs_live CHECK (state <> 'LIVE' OR went_live_at IS NOT NULL),
                ADD CONSTRAINT campaign_runs_went_live CHECK (went_live_at IS NULL OR state IN ('LIVE', 'ENDED')),
                ADD CONSTRAINT campaign_runs_ended CHECK ((ended_at IS NOT NULL) = (state = 'ENDED')),
                ADD CONSTRAINT campaign_runs_cancelled CHECK ((cancelled_at IS NOT NULL) = (state = 'CANCELLED')),
                ADD CONSTRAINT campaign_runs_warned CHECK ((warned_72_at IS NULL AND warned_24_at IS NULL) OR went_live_at IS NOT NULL)
            SQL);

        // One notice serves one run while that run may still send it (§1.7 #10): a cancelled run's tie is cleared,
        // a live or ended one has sent it already.
        DB::statement("CREATE UNIQUE INDEX campaign_runs_notice ON content.campaign_runs (notice_id) WHERE notice_id IS NOT NULL AND state IN ('DRAFT', 'SCHEDULED')");
        // The shop's campaign page (H7, PR 10): an address per store and language.
        DB::statement('CREATE UNIQUE INDEX campaign_runs_slug_ar ON content.campaign_runs (store_id, slug_ar) WHERE slug_ar IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX campaign_runs_slug_en ON content.campaign_runs (store_id, slug_en) WHERE slug_en IS NOT NULL');

        Schema::create('content.campaign_run_targets', function (Blueprint $table) {
            $table->ulid('run_id');
            $table->ulid('category_id')->nullable();
            $table->ulid('brand_id')->nullable();

            $table->foreign('run_id', 'crt_run')->references('id')->on('content.campaign_runs')->cascadeOnDelete();
            $table->foreign('category_id', 'crt_category')->references('id')->on('catalog.categories')->restrictOnDelete();
            $table->foreign('brand_id', 'crt_brand')->references('id')->on('catalog.brands')->restrictOnDelete();
            $table->index('category_id', 'crt_category_idx');
            $table->index('brand_id', 'crt_brand_idx');
        });

        DB::statement('ALTER TABLE content.campaign_run_targets ADD CONSTRAINT crt_one CHECK ((category_id IS NULL) <> (brand_id IS NULL))');
        DB::statement('CREATE UNIQUE INDEX crt_run_category ON content.campaign_run_targets (run_id, category_id) WHERE category_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX crt_run_brand ON content.campaign_run_targets (run_id, brand_id) WHERE brand_id IS NOT NULL');

        DB::statement('ALTER TABLE content.smart_bars ADD CONSTRAINT smart_bars_run FOREIGN KEY (run_id) REFERENCES content.campaign_runs (id) ON DELETE CASCADE');
        DB::statement('CREATE INDEX smart_bars_run_idx ON content.smart_bars (run_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS content.smart_bars_run_idx');
        DB::statement("DELETE FROM content.smart_bars WHERE scope = 'CAMPAIGN'");
        DB::statement('ALTER TABLE content.smart_bars DROP CONSTRAINT IF EXISTS smart_bars_run');
        Schema::dropIfExists('content.campaign_run_targets');
        Schema::dropIfExists('content.campaign_runs');
        Schema::dropIfExists('content.campaigns');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| The owner's decisions of 2026-11-20 on policies: a published text stays readable to shoppers once it is no longer valid,
| labelled with the dates it was in force (from - to), and turning a store off ends the period in THAT store only (a
| policy shared by KSA and Egypt and turned off in KSA is still in force in Egypt).
|
| So each store keeps its own record: a row per version of a policy in force in a store, at the addresses it had there,
| from when to when (`ended_at` empty while it still is). A row opens when a version becomes the one in force in a store
| (a publish, a new version, a store added) and closes when it stops (the next version published, the store turned off or
| taken off, the policy unpublished, an address changed - then a new row opens at the new address). The code keeps at most
| one open row per policy and store (`DatabasePolicies::settlePeriods`, under the policies' lock); a partial unique index
| would say it too, but has no standard SQL form (CONVENTIONS, "Database").
|
| Each row names its version (the key to content.policy_versions): only a published text is ever in force, and a policy
| once published is never deleted. Its store's key is RESTRICT, as policy_stores'.
|
| Rows for the policies already published when this runs are made from what is known (nothing is live yet): each version
| in force from its publish to the next one's in the stores the policy serves now, at its addresses now; the last open
| while published, else closed at the policy's last change.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.policy_periods', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('policy_id');
            $table->integer('number');
            $table->ulid('store_id');
            $table->string('slug_ar', 200);
            $table->string('slug_en', 200);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();

            $table->primary('id', 'policy_periods_pkey');
            $table->foreign(['policy_id', 'number'], 'polp_version')->references(['policy_id', 'number'])->on('content.policy_versions')->cascadeOnDelete();
            $table->foreign('store_id', 'polp_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['store_id', 'policy_id', 'number'], 'polp_store_policy_idx');
            $table->index(['policy_id', 'ended_at'], 'polp_policy_open_idx');
        });

        // Standard SQL (CONVENTIONS, "Database"); the code never writes an end before its start (it ends a row "now").
        DB::statement('ALTER TABLE content.policy_periods ADD CONSTRAINT polp_dates CHECK (ended_at IS NULL OR ended_at >= started_at)');

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('content.policy_periods');
    }

    /** The rows of the policies published before this table existed, from what is known (see above). */
    private function backfill(): void
    {
        foreach (DB::table('content.policies')->where('text_version', '>', 0)->orderBy('id')->get() as $policy) {
            $slugs = DB::table('content.policy_translations')->where('policy_id', $policy->id)->pluck('slug', 'locale')->all();
            $versions = DB::table('content.policy_versions')->where('policy_id', $policy->id)->orderBy('number')->get(['number', 'published_at'])->all();
            $stores = DB::table('content.policy_stores')->where('policy_id', $policy->id)->pluck('store_id')->all();

            if (! isset($slugs['ar'], $slugs['en'])) {
                continue;
            }

            foreach ($versions as $index => $version) {
                $next = $versions[$index + 1] ?? null;
                $last = $next === null;
                $end = $last ? ($policy->status === 'PUBLISHED' ? null : $policy->updated_at) : $next->published_at;

                foreach ($stores as $store) {
                    DB::table('content.policy_periods')->insert([
                        'id' => strtolower((string) Str::ulid()),
                        'policy_id' => $policy->id,
                        'number' => $version->number,
                        'store_id' => $store,
                        'slug_ar' => $slugs['ar'],
                        'slug_en' => $slugs['en'],
                        'started_at' => $version->published_at,
                        'ended_at' => $end,
                    ]);
                }
            }
        }
    }
};

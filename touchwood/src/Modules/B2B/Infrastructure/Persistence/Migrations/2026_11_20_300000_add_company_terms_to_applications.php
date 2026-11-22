<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The store's Company Terms an application was sent under (flags-2026-11.md S3.6, Q2 - owner: yes): Content's policy
| and the version of its text the company ticked, kept as plain values - B2B reads them only through Platform's
| `ShopPolicies` and never reaches Content's tables (owner, 2026-11-20). When, is the application's `submitted_at`.
| Empty for a draft, for one sent before, and for one sent while its store had no Company Terms published.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b.applications', static function (Blueprint $table): void {
            $table->ulid('company_terms_policy_id')->nullable();
            $table->integer('company_terms_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('b2b.applications', static function (Blueprint $table): void {
            $table->dropColumn(['company_terms_policy_id', 'company_terms_version']);
        });
    }
};

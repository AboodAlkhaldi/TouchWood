<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The terms a customer accepts at sign-up come from the store's published Terms and Conditions (flags-2026-11.md S3.4,
| F18): the policy and the version of its text, both Content's, kept as plain values - Access reads them only through
| Platform's `ShopPolicies` and never reaches Content's tables (owner, 2026-11-20).
|
| - `terms_version`, the version a staff member typed into `access.customer.terms_version`, is kept for the accounts
|   made before: it is what they accepted, and is history now. New accounts leave it empty.
| - `terms_policy_id` and `terms_policy_version` name the policy and the version accepted; empty for the accounts
|   made before, and for one made while its store had no published terms (the owner, 2026-11-20: sign-up is not refused).
|   The Customer object holds the rule (both or neither, a version of 1 or more); no CHECK repeats it.
| - `privacy_policy_id` and `privacy_policy_version`, the same for the store's Privacy Policy, which the same tick
|   accepts (owner, 2026-11-20).
| - The typed setting is retired: its declaration is gone and so are the values stored for it. Going down does not
|   bring those values back - the setting no longer exists to hold them.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access.customers', static function (Blueprint $table): void {
            $table->string('terms_version', 32)->nullable()->change();
            $table->ulid('terms_policy_id')->nullable();
            $table->integer('terms_policy_version')->nullable();
            $table->ulid('privacy_policy_id')->nullable();
            $table->integer('privacy_policy_version')->nullable();
        });

        DB::table('platform.settings')->where('key', 'access.customer.terms_version')->delete();
    }

    public function down(): void
    {
        Schema::table('access.customers', static function (Blueprint $table): void {
            $table->dropColumn(['terms_policy_id', 'terms_policy_version', 'privacy_policy_id', 'privacy_policy_version']);
        });
        // The accounts made since have no typed version; the old default stands in, as it did for an unset setting.
        DB::table('access.customers')->whereNull('terms_version')->update(['terms_version' => '2026-01']);
        Schema::table('access.customers', static function (Blueprint $table): void {
            $table->string('terms_version', 32)->nullable(false)->change();
        });
    }
};

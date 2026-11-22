<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Access spec §1.8 and §5.2: a customer's password reset link, and the session version that ends
| every other session when the password changes — the same rule staff already follow. Links are
| stored only as hashes.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access.customers', function (Blueprint $table) {
            // Raised when the password changes: every session signed in before then ends.
            $table->unsignedInteger('session_version')->default(0);
        });

        Schema::create('access.customer_password_resets', function (Blueprint $table) {
            // One live link per customer: a new one replaces it.
            $table->ulid('customer_id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');

            $table->foreign('customer_id')->references('id')->on('access.customers')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE access.customers ADD CONSTRAINT customers_session_version_not_negative CHECK (session_version >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('access.customer_password_resets');
        Schema::table('access.customers', function (Blueprint $table) {
            $table->dropColumn('session_version');
        });
    }
};

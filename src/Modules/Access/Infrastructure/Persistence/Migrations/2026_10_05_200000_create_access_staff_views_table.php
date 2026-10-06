<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The staff view's passes (access.md §1.11, amendment 60): a staff member looking at the shop from
| the panel. Its token lives in the browser's own cookie and only its hash here, as a trusted
| browser's does. Each pass belongs to the admin session that opened it - one each - so that session
| ending can end it, and it is gone with the staff member's account.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access.staff_views', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('staff_user_id', 26);
            $table->char('token_hash', 64)->unique();
            // A hash of the admin session's id, never the id itself: that id is the panel's cookie.
            $table->char('admin_session_hash', 64);
            $table->char('store_id', 26);
            $table->integer('session_version');
            // When that admin session signed in: the pass ends when the session would (§1.8).
            $table->timestampTz('signed_in_at');
            $table->timestampTz('seen_at');
            $table->timestampTz('created_at');

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['staff_user_id', 'admin_session_hash']);
            // Signing out ends that session's passes by its hash alone.
            $table->index('admin_session_hash');
            // Passes past the maximum are cleared by when they signed in.
            $table->index('signed_in_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.staff_views
                ADD CONSTRAINT staff_views_token_hash_format CHECK (token_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT staff_views_admin_session_hash_format CHECK (admin_session_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT staff_views_seen_after_sign_in CHECK (seen_at >= signed_in_at)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('access.staff_views');
    }
};

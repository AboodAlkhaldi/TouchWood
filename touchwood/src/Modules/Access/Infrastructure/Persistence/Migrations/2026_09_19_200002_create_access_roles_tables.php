<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Access spec §5.4. The handoff's store_ids[] is a link table, so every store id has a real
| foreign key. Every CHECK and unique index here is first enforced in code (Role, RoleName,
| StoreChoice, the permission catalog and the handlers); the database is the last line of defence.
| A CHECK that evaluates to NULL passes, so the name rule is wrapped in COALESCE(…, false): a
| missing language would otherwise slip through.
*/

return new class extends Migration
{
    private const string PERMISSION = '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$';

    public function up(): void
    {
        Schema::create('access.roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->jsonb('name');
            $table->string('kind', 16);
            $table->string('level', 16);
            $table->char('personal_to', 26)->nullable();
            $table->timestampsTz();

            $table->foreign('personal_to')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.roles
                ADD CONSTRAINT roles_kind CHECK (kind IN ('SAVED', 'PERSONAL')),
                ADD CONSTRAINT roles_level CHECK (level IN ('ADMIN', 'STAFF')),
                ADD CONSTRAINT roles_personal_to_exactly_personal CHECK ((kind = 'PERSONAL') = (personal_to IS NOT NULL)),
                ADD CONSTRAINT roles_name_translated CHECK (COALESCE(
                    jsonb_typeof(name->'ar') = 'string' AND btrim(name->>'ar') <> ''
                    AND jsonb_typeof(name->'en') = 'string' AND btrim(name->>'en') <> '',
                    false
                ))
            SQL);
        // One personal role per staff member; saved role names unique in each language, ignoring case.
        DB::statement('CREATE UNIQUE INDEX roles_one_personal_role ON access.roles (personal_to) WHERE personal_to IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX roles_saved_name_ar ON access.roles (lower(name->>'ar')) WHERE kind = 'SAVED'");
        DB::statement("CREATE UNIQUE INDEX roles_saved_name_en ON access.roles (lower(name->>'en')) WHERE kind = 'SAVED'");

        Schema::create('access.role_permissions', function (Blueprint $table) {
            $table->char('role_id', 26);
            $table->string('permission', 128);

            $table->primary(['role_id', 'permission']);
            $table->foreign('role_id')->references('id')->on('access.roles')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE access.role_permissions ADD CONSTRAINT role_permissions_name CHECK (permission ~ '".self::PERMISSION."')");

        Schema::create('access.role_assignments', function (Blueprint $table) {
            $table->char('staff_user_id', 26)->primary();
            $table->char('role_id', 26);
            $table->string('access_level', 16);
            $table->char('assigned_by', 26)->nullable();
            $table->timestampTz('assigned_at');

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('access.roles')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->index('role_id');
        });

        DB::statement("ALTER TABLE access.role_assignments ADD CONSTRAINT role_assignments_access_level CHECK (access_level IN ('ALL_STORES', 'SELECTED_STORES'))");

        Schema::create('access.role_assignment_stores', function (Blueprint $table) {
            $table->char('staff_user_id', 26);
            $table->char('store_id', 26);

            $table->primary(['staff_user_id', 'store_id']);
            $table->foreign('staff_user_id')->references('staff_user_id')->on('access.role_assignments')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        Schema::create('access.role_assignment_exceptions', function (Blueprint $table) {
            $table->char('staff_user_id', 26);
            $table->string('permission', 128);
            $table->string('access_level', 16);

            $table->primary(['staff_user_id', 'permission']);
            $table->foreign('staff_user_id')->references('staff_user_id')->on('access.role_assignments')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE access.role_assignment_exceptions ADD CONSTRAINT role_assignment_exceptions_name CHECK (permission ~ '".self::PERMISSION."')");
        DB::statement("ALTER TABLE access.role_assignment_exceptions ADD CONSTRAINT role_assignment_exceptions_access_level CHECK (access_level IN ('ALL_STORES', 'SELECTED_STORES'))");

        Schema::create('access.role_assignment_exception_stores', function (Blueprint $table) {
            $table->char('staff_user_id', 26);
            $table->string('permission', 128);
            $table->char('store_id', 26);

            $table->primary(['staff_user_id', 'permission', 'store_id']);
            // A renamed permission renames its exception's stores with it.
            $table->foreign(['staff_user_id', 'permission'])->references(['staff_user_id', 'permission'])
                ->on('access.role_assignment_exceptions')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('store_id')->references('id')->on('platform.stores')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access.role_assignment_exception_stores');
        Schema::dropIfExists('access.role_assignment_exceptions');
        Schema::dropIfExists('access.role_assignment_stores');
        Schema::dropIfExists('access.role_assignments');
        Schema::dropIfExists('access.role_permissions');
        Schema::dropIfExists('access.roles');
    }
};

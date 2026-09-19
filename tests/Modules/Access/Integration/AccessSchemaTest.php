<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function insertRoleRow(array $overrides = []): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('access.roles')->insert([
        'id' => $id,
        'name' => json_encode(['ar' => 'دور '.$id, 'en' => 'Role '.$id]),
        'kind' => 'SAVED',
        'level' => 'STAFF',
        'personal_to' => null,
        ...$overrides,
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertAssignmentRow(string $staffId, array $overrides = []): void
{
    DB::table('access.role_assignments')->insert([
        'staff_user_id' => $staffId,
        'role_id' => insertRoleRow(),
        'access_level' => 'ALL_STORES',
        'assigned_at' => now(),
        ...$overrides,
    ]);
}

it('creates the access tables', function (string $table) {
    expect(DB::table('information_schema.tables')->where('table_schema', 'access')->where('table_name', $table)->exists())->toBeTrue();
})->with([
    'staff_users', 'roles', 'role_permissions', 'role_assignments', 'role_assignment_stores',
    'role_assignment_exceptions', 'role_assignment_exception_stores',
]);

it('allows in each enum column exactly the values of its PHP enum', function (string $constraint, array $cases) {
    $definition = DB::selectOne('select pg_get_constraintdef(oid) as definition from pg_constraint where conname = ?', [$constraint])?->definition;
    preg_match('/ARRAY\[([^\]]*)\]/', (string) $definition, $list);
    preg_match_all("/'([A-Z_]+)'::/", $list[1] ?? '', $allowed);

    expect($allowed[1])->toEqualCanonicalizing(array_map(fn (BackedEnum $case): string|int => $case->value, $cases));
})->with([
    'staff status' => ['staff_users_status', StaffStatus::cases()],
    'role kind' => ['roles_kind', RoleKind::cases()],
    'role level' => ['roles_level', RoleLevel::cases()],
    'store row' => ['role_assignments_access_level', AccessLevel::cases()],
    'an action\'s own stores' => ['role_assignment_exceptions_access_level', AccessLevel::cases()],
]);

it('refuses rows that break the rules, even when they skip the domain', function (Closure $insert, string $constraint) {
    expect($insert)->toThrow(QueryException::class, $constraint);
})->with([
    'an unknown staff status' => [fn () => DB::table('access.staff_users')->where('id', Fx::staff())->update(['status' => 'GONE']), 'staff_users_status'],
    'an unknown role kind' => [fn () => insertRoleRow(['kind' => 'SHARED']), 'roles_kind'],
    'an unknown role level' => [fn () => insertRoleRow(['level' => 'OWNER']), 'roles_level'],
    'a personal role without its staff member' => [fn () => insertRoleRow(['kind' => 'PERSONAL']), 'roles_personal_to_exactly_personal'],
    'a saved role belonging to someone' => [fn () => insertRoleRow(['personal_to' => Fx::staff()]), 'roles_personal_to_exactly_personal'],
    'a role name without English' => [fn () => insertRoleRow(['name' => json_encode(['ar' => 'دور'])]), 'roles_name_translated'],
    'a blank Arabic role name' => [fn () => insertRoleRow(['name' => json_encode(['ar' => ' ', 'en' => 'Role'])]), 'roles_name_translated'],
    'two personal roles for one staff member' => [function () {
        $staffId = Fx::staff();
        insertRoleRow(['kind' => 'PERSONAL', 'personal_to' => $staffId]);
        insertRoleRow(['kind' => 'PERSONAL', 'personal_to' => $staffId]);
    }, 'roles_one_personal_role'],
    'two saved roles with one English name' => [function () {
        insertRoleRow(['name' => json_encode(['ar' => 'أ', 'en' => 'Support'])]);
        insertRoleRow(['name' => json_encode(['ar' => 'ب', 'en' => 'SUPPORT'])]);
    }, 'roles_saved_name_en'],
    'two saved roles with one Arabic name' => [function () {
        insertRoleRow(['name' => json_encode(['ar' => 'الدعم', 'en' => 'A'])]);
        insertRoleRow(['name' => json_encode(['ar' => 'الدعم', 'en' => 'B'])]);
    }, 'roles_saved_name_ar'],
    'a malformed permission' => [fn () => DB::table('access.role_permissions')->insert(['role_id' => insertRoleRow(), 'permission' => 'Store.Update']), 'role_permissions_name'],
    'an unknown store row' => [fn () => insertAssignmentRow(Fx::staff(), ['access_level' => 'SOME']), 'role_assignments_access_level'],
    'a store that does not exist' => [function () {
        $staffId = Fx::staff();
        insertAssignmentRow($staffId, ['access_level' => 'SELECTED_STORES']);
        DB::table('access.role_assignment_stores')->insert(['staff_user_id' => $staffId, 'store_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3']);
    }, 'role_assignment_stores_store_id_foreign'],
    'an exception with a malformed permission' => [function () {
        $staffId = Fx::staff();
        insertAssignmentRow($staffId);
        DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $staffId, 'permission' => 'refund', 'access_level' => 'ALL_STORES']);
    }, 'role_assignment_exceptions_name'],
    'deleting a role someone holds' => [function () {
        $staffId = Fx::staff();
        insertAssignmentRow($staffId);
        DB::table('access.roles')->where('id', DB::table('access.role_assignments')->where('staff_user_id', $staffId)->value('role_id'))->delete();
    }, 'role_assignments_role_id_foreign'],
]);

it('lets two personal roles and saved names coexist when they are personal', function () {
    insertRoleRow(['kind' => 'PERSONAL', 'personal_to' => Fx::staff(), 'name' => json_encode(['ar' => 'الدعم', 'en' => 'Support'])]);
    insertRoleRow(['kind' => 'PERSONAL', 'personal_to' => Fx::staff(), 'name' => json_encode(['ar' => 'الدعم', 'en' => 'Support'])]);
    insertRoleRow(['name' => json_encode(['ar' => 'الدعم', 'en' => 'Support'])]);

    expect(DB::table('access.roles')->count())->toBe(3);
});

it('turns a name taken at the same moment into a clear error, not a database error', function () {
    // As if another admin saved the name between our check and our insert.
    insertRoleRow(['name' => json_encode(['ar' => 'الدعم', 'en' => 'Support'])]);
    $role = Role::saved(strtolower((string) Str::ulid()), RoleLevel::Staff, RoleName::of('دعم آخر', 'support'), ['platform.store.update']);

    expect(fn () => DB::transaction(fn () => app(RoleRepository::class)->add($role)))->toThrow(RoleNameTaken::class, 'support');
});

it('moves an exception\'s stores with it when its permission is renamed', function () {
    $staffId = Fx::staff();
    insertAssignmentRow($staffId, ['access_level' => 'SELECTED_STORES']);
    DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $staffId, 'permission' => 'sales.order.edit', 'access_level' => 'SELECTED_STORES']);
    DB::table('access.role_assignment_exception_stores')->insert(['staff_user_id' => $staffId, 'permission' => 'sales.order.edit', 'store_id' => Fx::storeId('sa')]);

    DB::table('access.role_assignment_exceptions')->where('staff_user_id', $staffId)->update(['permission' => 'sales.order.update']);

    expect(DB::table('access.role_assignment_exception_stores')->where('staff_user_id', $staffId)->value('permission'))->toBe('sales.order.update');
});

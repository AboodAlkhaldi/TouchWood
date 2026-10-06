<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\CustomerPhoneCodePurpose;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Access\Public\Enums\StaffStatus;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Registering a customer checks the password against the breach list: never the real one.
    FakeBreachList::install();
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

/**
 * Changes one active staff member's row, as if the domain were skipped.
 *
 * @param  array<string, mixed>  $values
 */
function updateStaffRow(array $values): void
{
    DB::table('access.staff_users')->where('id', Fx::staff())->update($values);
}

function emailOfRow(string $staffId): string
{
    return (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertPhoneCodeRow(array $overrides): void
{
    DB::table('access.staff_phone_codes')->insert([
        'staff_user_id' => Fx::staff(), 'purpose' => 'CHANGE', 'phone' => '+966501234567', 'code_hash' => hash('sha256', 'code'),
        'attempts' => 0, 'expires_at' => now(), 'sent_at' => now(), ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $values
 */
function updateCustomerRow(array $values): void
{
    DB::table('access.customers')->where('id', Fx::customer())->update($values);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertAddressRow(array $overrides = []): void
{
    DB::table('access.addresses')->insert([
        'id' => strtolower((string) Str::ulid()),
        // Only when the caller gives none: registering the same email twice would fail first.
        'customer_id' => $overrides['customer_id'] ?? Fx::customer(),
        'store_id' => Fx::storeId('sa'),
        'label' => 'Home',
        'recipient_name' => 'Sara Ali',
        'phone' => '+966501234567',
        'fields' => json_encode(['city' => 'Riyadh']),
        'is_default' => false,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCustomerPhoneCodeRow(array $overrides): void
{
    DB::table('access.phone_codes')->insert([
        'customer_id' => Fx::customer(), 'purpose' => 'ADD', 'phone' => '+966501234567', 'code_hash' => hash('sha256', 'code'),
        'attempts' => 0, 'expires_at' => now(), 'sent_at' => now(), ...$overrides,
    ]);
}

it('creates the access tables', function (string $table) {
    expect(DB::table('information_schema.tables')->where('table_schema', 'access')->where('table_name', $table)->exists())->toBeTrue();
})->with([
    'staff_users', 'roles', 'role_permissions', 'role_assignments', 'role_assignment_stores',
    'role_assignment_exceptions', 'role_assignment_exception_stores',
    'staff_invitations', 'staff_phone_codes', 'staff_email_changes', 'staff_notification_preferences',
    'staff_sign_in_codes', 'staff_password_resets', 'staff_trusted_browsers',
    'customers', 'phone_codes', 'customer_password_resets',
    'addresses', 'store_address_formats',
]);

it('allows in each enum column exactly the values of its PHP enum', function (string $constraint, array $cases) {
    $definition = DB::selectOne('select pg_get_constraintdef(oid) as definition from pg_constraint where conname = ?', [$constraint])?->definition;
    preg_match('/ARRAY\[([^\]]*)\]/', (string) $definition, $list);
    preg_match_all("/'([A-Za-z_]+)'::/", $list[1] ?? '', $allowed);

    expect($allowed[1])->toEqualCanonicalizing(array_map(fn (BackedEnum $case): string|int => $case->value, $cases));
})->with([
    'staff status' => ['staff_users_status', StaffStatus::cases()],
    'communication language' => ['staff_users_locale', Language::cases()],
    'what a phone code is for' => ['staff_phone_codes_purpose', PhoneCodePurpose::verifyingPhone()],
    'notification topic' => ['staff_notification_preferences_topic', StaffNotificationTopic::cases()],
    'role kind' => ['roles_kind', RoleKind::cases()],
    'role level' => ['roles_level', RoleLevel::cases()],
    'store row' => ['role_assignments_access_level', AccessLevel::cases()],
    'an action\'s own stores' => ['role_assignment_exceptions_access_level', AccessLevel::cases()],
    'customer status' => ['customers_status', CustomerStatus::cases()],
    'account type' => ['customers_account_type', AccountType::cases()],
    'a customer\'s language' => ['customers_locale', Language::cases()],
    'what a customer\'s phone code is for' => ['phone_codes_purpose', CustomerPhoneCodePurpose::cases()],
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
    'an exception store that does not exist' => [function () {
        $staffId = Fx::staff();
        insertAssignmentRow($staffId);
        DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $staffId, 'permission' => 'platform.store.update', 'access_level' => 'SELECTED_STORES']);
        DB::table('access.role_assignment_exception_stores')->insert(['staff_user_id' => $staffId, 'permission' => 'platform.store.update', 'store_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3']);
    }, 'role_assignment_exception_stores_store_id_foreign'],
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
    'an unknown language' => [fn () => updateStaffRow(['locale' => 'fr']), 'staff_users_locale'],
    'a customer phone without its country code' => [fn () => updateCustomerRow(['phone' => '0501234567', 'phone_verified_at' => now()]), 'customers_phone_format'],
    'a customer phone verified but not there' => [fn () => updateCustomerRow(['phone_verified_at' => now()]), 'customers_phone_verified_together'],
    'a customer phone there but unverified' => [fn () => updateCustomerRow(['phone' => '+966501234567']), 'customers_phone_verified_together'],
    'an unknown customer status' => [fn () => updateCustomerRow(['status' => 'SLEEPING']), 'customers_status'],
    'a customer changing what kind of account it is' => [fn () => updateCustomerRow(['account_type' => 'COMPANY']), 'account_type is set at registration'],
    'a customer changing their home store' => [fn () => updateCustomerRow(['home_store_id' => Fx::storeId('ae')]), 'home_store_id is set at registration'],
    'a customer session version below zero' => [fn () => updateCustomerRow(['session_version' => -1]), 'customers_session_version_not_negative'],
    'an address with half a map pin' => [fn () => insertAddressRow(['latitude' => 24.7]), 'addresses_map_pin_together'],
    'an address pin off the globe' => [fn () => insertAddressRow(['latitude' => 91, 'longitude' => 0]), 'addresses_map_pin_range'],
    'an address phone without its country code' => [fn () => insertAddressRow(['phone' => '0501234567']), 'addresses_phone_format'],
    'address fields that are not an object' => [fn () => insertAddressRow(['fields' => '[]']), 'addresses_fields_object'],
    'two default addresses in one store' => [function () {
        $customerId = Fx::customer();
        insertAddressRow(['customer_id' => $customerId, 'is_default' => true]);
        insertAddressRow(['customer_id' => $customerId, 'is_default' => true]);
    }, 'addresses_one_default_per_store'],
    'one customer reset link for two people' => [function () {
        DB::table('access.customer_password_resets')->insert(['customer_id' => Fx::customer(), 'token_hash' => hash('sha256', 't'), 'expires_at' => now(), 'created_at' => now()]);
        DB::table('access.customer_password_resets')->insert(['customer_id' => Fx::customer('second@example.test'), 'token_hash' => hash('sha256', 't'), 'expires_at' => now(), 'created_at' => now()]);
    }, 'customer_password_resets_token_hash_unique'],
    'a phone code with a purpose we do not have' => [fn () => insertCustomerPhoneCodeRow(['purpose' => 'REMOVE']), 'phone_codes_purpose'],
    'a phone code with a malformed number' => [fn () => insertCustomerPhoneCodeRow(['phone' => '0501234567']), 'phone_codes_phone_format'],
    'a country not in capitals' => [fn () => updateStaffRow(['country' => 'sa']), 'staff_users_country_format'],
    'a phone without its country code' => [fn () => updateStaffRow(['phone' => '0501234567']), 'staff_users_phone_format'],
    'a verified phone that is not there' => [fn () => updateStaffRow(['phone' => null]), 'staff_users_phone_verified_has_phone'],
    'an active account without a password' => [fn () => updateStaffRow(['password' => null]), 'staff_users_active_has_password'],
    'an invitation with a password' => [fn () => updateStaffRow(['status' => 'INVITED']), 'staff_users_invited_has_no_password'],
    'a disabled account that never accepted' => [fn () => updateStaffRow(['status' => 'DISABLED', 'password' => null]), 'staff_users_disabled_has_password'],
    'a cancelled account with a password' => [fn () => updateStaffRow(['status' => 'CANCELLED']), 'staff_users_cancelled_has_no_password'],
    'invited by themselves' => [function () {
        $staffId = Fx::staff();
        DB::table('access.staff_users')->where('id', $staffId)->update(['invited_by' => $staffId]);
    }, 'staff_users_not_invited_by_self'],
    'invited by someone unknown' => [fn () => updateStaffRow(['invited_by' => '01j8z3k4m5n6p7q8r9s0t1v2w3']), 'staff_users_invited_by_foreign'],
    'a sign-in code sent to a malformed phone' => [fn () => DB::table('access.staff_sign_in_codes')->insert([
        'staff_user_id' => Fx::staff(), 'phone' => '966501234567', 'code_hash' => hash('sha256', 'code'), 'attempts' => 0, 'expires_at' => now(), 'sent_at' => now(),
    ]), 'staff_sign_in_codes_phone_format'],
    'one reset link for two people' => [function () {
        DB::table('access.staff_password_resets')->insert(['staff_user_id' => Fx::staff(), 'token_hash' => hash('sha256', 't'), 'expires_at' => now(), 'created_at' => now()]);
        DB::table('access.staff_password_resets')->insert(['staff_user_id' => Fx::staff(), 'token_hash' => hash('sha256', 't'), 'expires_at' => now(), 'created_at' => now()]);
    }, 'staff_password_resets_token_hash_unique'],
    'one trust token for two browsers' => [function () {
        foreach ([1, 2] as $n) {
            DB::table('access.staff_trusted_browsers')->insert([
                'id' => strtolower((string) Str::ulid()), 'staff_user_id' => Fx::staff(), 'token_hash' => hash('sha256', 't'),
                'expires_at' => now(), 'created_at' => now(), 'last_used_at' => now(),
            ]);
        }
    }, 'staff_trusted_browsers_token_hash_unique'],
    'born on 1 January 1900' => [fn () => updateStaffRow(['date_of_birth' => '1900-01-01']), 'staff_users_date_of_birth_range'],
    'a Super Admin not marked as one' => [fn () => DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->update(['was_super_admin' => false]), 'staff_users_super_admin_marked'],
    'one email twice, in another case' => [fn () => updateStaffRow(['email' => strtoupper(emailOfRow(Fx::staff()))]), 'staff_users_email_unique'],
    'one phone twice' => [fn () => updateStaffRow(['phone' => DB::table('access.staff_users')->where('id', Fx::staff())->value('phone')]), 'staff_users_phone_unique'],
    'deleting a photo used as an avatar' => [function () {
        $mediaId = strtolower((string) Str::ulid());
        DB::table('platform.media')->insert([
            'id' => $mediaId, 'visibility' => 'PUBLIC', 'disk' => 'local', 'object_key' => "media/{$mediaId}.jpg",
            'original_filename' => 'face.jpg', 'mime' => 'image/jpeg', 'bytes' => 1000, 'width' => 10, 'height' => 10,
            'checksum' => hash('sha256', $mediaId), 'variants_status' => 'READY', 'variants_queued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        updateStaffRow(['avatar_media_id' => $mediaId]);
        DB::table('platform.media')->where('id', $mediaId)->delete();
    }, 'staff_users_avatar_media_id_foreign'],
    'an unknown phone code purpose' => [fn () => insertPhoneCodeRow(['purpose' => 'LOGIN']), 'staff_phone_codes_purpose'],
    'a code sent to a malformed phone' => [fn () => insertPhoneCodeRow(['phone' => '966501234567']), 'staff_phone_codes_phone_format'],
    'negative attempts' => [fn () => insertPhoneCodeRow(['attempts' => -1]), 'staff_phone_codes_attempts'],
    'an unknown notification topic' => [fn () => DB::table('access.staff_notification_preferences')->insert(['staff_user_id' => Fx::staff(), 'topic' => 'GOSSIP', 'email' => false, 'panel' => true]), 'staff_notification_preferences_topic'],
    'one invitation link for two people' => [function () {
        $hash = hash('sha256', 'token');
        DB::table('access.staff_invitations')->insert(['staff_user_id' => Fx::staff(StaffStatus::Invited), 'token_hash' => $hash, 'expires_at' => now(), 'created_at' => now()]);
        DB::table('access.staff_invitations')->insert(['staff_user_id' => Fx::staff(StaffStatus::Invited), 'token_hash' => $hash, 'expires_at' => now(), 'created_at' => now()]);
    }, 'staff_invitations_token_hash_unique'],
    'one email-change link for two people' => [function () {
        $hash = hash('sha256', 'token');
        DB::table('access.staff_email_changes')->insert(['staff_user_id' => Fx::staff(), 'new_email' => 'a@example.test', 'token_hash' => $hash, 'expires_at' => now(), 'created_at' => now()]);
        DB::table('access.staff_email_changes')->insert(['staff_user_id' => Fx::staff(), 'new_email' => 'b@example.test', 'token_hash' => $hash, 'expires_at' => now(), 'created_at' => now()]);
    }, 'staff_email_changes_token_hash_unique'],
]);

it('lets a new account use the email and phone of a cancelled one', function () {
    $cancelled = Fx::staff(StaffStatus::Invited);
    DB::table('access.staff_users')->where('id', $cancelled)->update(['status' => 'CANCELLED']);
    $row = (array) DB::table('access.staff_users')->where('id', $cancelled)->first();
    $again = Fx::staff(StaffStatus::Invited);

    DB::table('access.staff_users')->where('id', $again)->update(['email' => strtoupper((string) $row['email']), 'phone' => $row['phone']]);

    expect(DB::table('access.staff_users')->where('phone', $row['phone'])->count())->toBe(2);
});

it('turns an email or phone taken at the same moment into a clear error, not a database error', function (Closure $taken, string $error) {
    // As if another admin saved the same email or phone between our check and our insert.
    $staff = StaffUser::invite(strtolower((string) Str::ulid()), EmailAddress::of('new@example.test'), StaffProfile::of('A', 'B', 'C', '1990-01-01', 'SA', null), PhoneNumber::of('+966501112233'), Language::English, null);
    $taken();

    expect(fn () => DB::transaction(fn () => app(StaffUserRepository::class)->add($staff)))->toThrow($error);
})->with([
    'the email' => [fn () => updateStaffRow(['email' => 'NEW@example.test']), StaffEmailInUse::class],
    'the phone' => [fn () => updateStaffRow(['phone' => '+966501112233']), PhoneAlreadyInUse::class],
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

it('marks every Super Admin, current or revoked, when the mark is added, from the flag and the audit log (amendment 57)', function () {
    $current = Fx::staff(superAdmin: true);
    $revoked = Fx::formerSuperAdmin();
    $ordinary = Fx::staff();
    // An ordinary invitation records the flag as false: that is not a Super Admin.
    DB::table('platform.audit_entries')->insert([
        'occurred_at' => now(), 'recorded_at' => now(), 'source' => 'WEB', 'store_id' => null,
        'actor_type' => 'STAFF', 'actor_id' => $current, 'action' => 'access.staff_user.invited',
        'subject_type' => 'access.staff_user', 'subject_id' => $ordinary,
        'changes' => json_encode(['is_super_admin' => [null, false]], JSON_THROW_ON_ERROR), 'ip_address' => null,
    ]);
    $migration = require base_path('src/Modules/Access/Infrastructure/Persistence/Migrations/2026_10_02_150000_add_access_staff_users_was_super_admin.php');

    // Back to the table as it was before the mark existed.
    $migration->down();
    expect(DB::getSchemaBuilder()->hasColumn('access.staff_users', 'was_super_admin'))->toBeFalse();

    $migration->up();
    $marked = static fn (string $id): bool => (bool) DB::table('access.staff_users')->where('id', $id)->value('was_super_admin');

    expect($marked($current))->toBeTrue()
        ->and($marked($revoked))->toBeTrue()
        ->and(DB::table('access.staff_users')->where('id', $revoked)->value('is_super_admin'))->toBeFalse()
        ->and($marked($ordinary))->toBeFalse();
});

it('stops, naming them, on actions whose own stores reach outside their row, and otherwise removes only an every-store choice under an every-store row (amendment 59)', function () {
    $migration = require base_path('src/Modules/Access/Infrastructure/Persistence/Migrations/2026_10_05_120000_guard_access_exceptions_within_reach.php');

    // Inside the row, or under a row of every store: nothing to stop on.
    $inside = Fx::staff();
    insertAssignmentRow($inside, ['access_level' => 'SELECTED_STORES']);
    DB::table('access.role_assignment_stores')->insert([['staff_user_id' => $inside, 'store_id' => Fx::storeId('sa')], ['staff_user_id' => $inside, 'store_id' => Fx::storeId('ae')]]);
    DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $inside, 'permission' => 'sales.order.edit', 'access_level' => 'SELECTED_STORES']);
    DB::table('access.role_assignment_exception_stores')->insert(['staff_user_id' => $inside, 'permission' => 'sales.order.edit', 'store_id' => Fx::storeId('sa')]);
    $everywhere = Fx::staff();
    insertAssignmentRow($everywhere);
    DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $everywhere, 'permission' => 'sales.order.edit', 'access_level' => 'ALL_STORES']);
    $underEvery = Fx::staff();
    insertAssignmentRow($underEvery);
    DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $underEvery, 'permission' => 'sales.order.edit', 'access_level' => 'SELECTED_STORES']);
    DB::table('access.role_assignment_exception_stores')->insert(['staff_user_id' => $underEvery, 'permission' => 'sales.order.edit', 'store_id' => Fx::storeId('sa')]);

    $migration->up();

    // "Every store" under an every-store row equals the row: removed, changing nobody's access. The
    // others are kept as they were.
    expect(DB::table('access.role_assignment_exceptions')->where('staff_user_id', $everywhere)->exists())->toBeFalse()
        ->and(DB::table('access.role_assignment_exceptions')->where('staff_user_id', $inside)->exists())->toBeTrue()
        ->and(DB::table('access.role_assignment_exceptions')->where('staff_user_id', $underEvery)->exists())->toBeTrue();

    // Written as the old rule allowed: a store the row lacks, and every store over a chosen one.
    $beyond = Fx::staff();
    insertAssignmentRow($beyond, ['access_level' => 'SELECTED_STORES']);
    DB::table('access.role_assignment_stores')->insert(['staff_user_id' => $beyond, 'store_id' => Fx::storeId('sa')]);
    DB::table('access.role_assignment_exceptions')->insert([
        ['staff_user_id' => $beyond, 'permission' => 'sales.order.edit', 'access_level' => 'SELECTED_STORES'],
        ['staff_user_id' => $beyond, 'permission' => 'sales.order.view', 'access_level' => 'ALL_STORES'],
    ]);
    DB::table('access.role_assignment_exception_stores')->insert(['staff_user_id' => $beyond, 'permission' => 'sales.order.edit', 'store_id' => Fx::storeId('eg')]);
    $before = DB::table('access.role_assignment_exceptions')->where('staff_user_id', $beyond)->count();

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, "{$beyond} sales.order.edit\n{$beyond} sales.order.view")
        // It changes nothing, either way.
        ->and(DB::table('access.role_assignment_exceptions')->where('staff_user_id', $beyond)->count())->toBe($before);
});

it('cuts every trusted browser to 12 hours from when it was trusted, and lengthens none (amendment 61)', function () {
    $trust = static function (string $trustedAgo, string $lasts): string {
        $id = strtolower((string) Str::ulid());
        $created = now()->sub(CarbonInterval::fromString($trustedAgo));
        DB::table('access.staff_trusted_browsers')->insert([
            'id' => $id, 'staff_user_id' => Fx::staff(), 'token_hash' => hash('sha256', $id),
            'created_at' => $created, 'expires_at' => $created->copy()->add(CarbonInterval::fromString($lasts)), 'last_used_at' => $created,
        ]);

        return $id;
    };
    // Trusted for 30 days, a day ago and an hour ago; and one already shorter than the new rule.
    $dayOld = $trust('1 day', '30 days');
    $hourOld = $trust('1 hour', '30 days');
    $shorter = $trust('1 hour', '2 hours');
    $hours = static fn (string $id): float => (float) DB::selectOne('SELECT EXTRACT(EPOCH FROM expires_at - created_at) / 3600 AS hours FROM access.staff_trusted_browsers WHERE id = ?', [$id])->hours;

    (require base_path('src/Modules/Access/Infrastructure/Persistence/Migrations/2026_10_05_210000_shorten_staff_trusted_browsers.php'))->up();

    // The day-old one has run out, so its browser asks for a code at its next sign-in.
    expect($hours($dayOld))->toBe(12.0)
        ->and($hours($hourOld))->toBe(12.0)
        ->and($hours($shorter))->toBe(2.0);
});

it('turns the Arabic-Indic digits saved before the rule into Latin, and nothing else (amendment 63)', function () {
    // Written straight in, as the rows saved before 2026-10-06 were: the value objects turn digits now.
    $customer = Fx::customer();
    insertAddressRow(['customer_id' => $customer, 'fields' => json_encode(['building' => '٧٢', 'postcode' => '۱۲۳۴۵', 'city' => 'الرياض'])]);
    insertAddressRow(['customer_id' => $customer, 'label' => 'Work', 'fields' => json_encode(['building' => '9', 'city' => 'Riyadh'])]);
    $staff = Fx::staff();
    DB::table('access.staff_users')->where('id', $staff)->update(['address' => "حي النخيل ٤\nالرياض ١٢٣٤٥"]);
    $fields = static fn (string $label): array => json_decode((string) DB::table('access.addresses')->where('customer_id', $customer)->where('label', $label)->value('fields'), true, flags: JSON_THROW_ON_ERROR);

    $migration = require base_path('src/Modules/Access/Infrastructure/Persistence/Migrations/2026_10_06_200000_latin_digits_in_access.php');
    $migration->up();

    expect($fields('Home'))->toBe(['city' => 'الرياض', 'building' => '72', 'postcode' => '12345'])
        ->and($fields('Work'))->toBe(['city' => 'Riyadh', 'building' => '9'])
        ->and(DB::table('access.staff_users')->where('id', $staff)->value('address'))->toBe("حي النخيل 4\nالرياض 12345");

    // Again: nothing left to turn.
    $migration->up();
    expect($fields('Home'))->toBe(['city' => 'الرياض', 'building' => '72', 'postcode' => '12345']);
});

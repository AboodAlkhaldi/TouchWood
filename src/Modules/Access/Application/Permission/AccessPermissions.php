<?php

declare(strict_types=1);

namespace Modules\Access\Application\Permission;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * Every permission Access checks (Access spec §3). Their names in Arabic and English are in
 * `access::permissions`.
 */
final class AccessPermissions
{
    // Visitors who are not signed in.
    public const string ACCOUNT_REGISTER = 'access.account.register';

    public const string SESSION_SIGN_IN = 'access.session.sign_in';

    public const string SESSION_RESET_PASSWORD = 'access.session.reset_password';

    public const string STAFF_ACCEPT_INVITATION = 'access.staff.accept_invitation';

    /**
     * The email verification link proves itself: whoever opens it verifies that address, signed in
     * or not (owner's decision, 2026-09-20; amendment 38) — see self::linkProved(). Verifying a
     * phone stays with the customer, under ACCOUNT_VERIFY.
     */
    public const string ACCOUNT_VERIFY_EMAIL = 'access.account.verify_email';

    // Every customer, for their own account.
    public const string ACCOUNT_VERIFY = 'access.account.verify';

    public const string ACCOUNT_UPDATE = 'access.account.update';

    public const string ACCOUNT_DELETE = 'access.account.delete';

    public const string ADDRESS_MANAGE = 'access.address.manage';

    public const string SESSION_SIGN_OUT = 'access.session.sign_out';

    // Every staff member, for their own account.
    public const string OWN_ACCOUNT_UPDATE = 'access.own_account.update';

    // Staff, through their role.
    public const string STAFF_INVITE = 'access.staff.invite';

    public const string STAFF_UPDATE = 'access.staff.update';

    public const string STAFF_ASSIGN_ROLE = 'access.staff.assign_role';

    public const string STAFF_DISABLE = 'access.staff.disable';

    public const string STAFF_VIEW = 'access.staff.view';

    public const string ROLE_MANAGE = 'access.role.manage';

    public const string CUSTOMER_VIEW = 'access.customer.view';

    public const string CUSTOMER_BLOCK = 'access.customer.block';

    public const string CUSTOMER_DELETE = 'access.customer.delete';

    public const string ADDRESS_FORMAT_UPDATE = 'access.address_format.update';

    public const string SETTINGS_UPDATE = 'access.settings.update';

    // Super Admins and the system only.
    public const string SUPER_ADMIN_MANAGE = 'access.super_admin.manage';

    public const string ACCOUNT_ANONYMIZE = 'access.account.anonymize';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $guest = PermissionAudience::EveryGuest;
        $customer = PermissionAudience::EveryCustomer;
        $staff = PermissionAudience::EveryStaff;
        // A person's own account belongs to no store (spec §3: "Global").
        $storeFree = PermissionKind::Global;

        return [
            new PermissionDefinitionDto(self::ACCOUNT_REGISTER, $guest, kind: $storeFree),
            new PermissionDefinitionDto(self::SESSION_SIGN_IN, $guest, kind: $storeFree),
            new PermissionDefinitionDto(self::SESSION_RESET_PASSWORD, $guest, kind: $storeFree),
            new PermissionDefinitionDto(self::STAFF_ACCEPT_INVITATION, $guest, kind: $storeFree),
            new PermissionDefinitionDto(self::ACCOUNT_VERIFY_EMAIL, $guest, kind: $storeFree),

            new PermissionDefinitionDto(self::ACCOUNT_VERIFY, $customer, kind: $storeFree),
            new PermissionDefinitionDto(self::ACCOUNT_UPDATE, $customer, kind: $storeFree),
            new PermissionDefinitionDto(self::ACCOUNT_DELETE, $customer, kind: $storeFree),
            // An address belongs to one store's country (spec §1.9).
            new PermissionDefinitionDto(self::ADDRESS_MANAGE, $customer),
            new PermissionDefinitionDto(self::SESSION_SIGN_OUT, $customer, kind: $storeFree),

            new PermissionDefinitionDto(self::OWN_ACCOUNT_UPDATE, $staff, kind: $storeFree),

            new PermissionDefinitionDto(self::STAFF_INVITE),
            new PermissionDefinitionDto(self::STAFF_UPDATE),
            new PermissionDefinitionDto(self::STAFF_ASSIGN_ROLE),
            new PermissionDefinitionDto(self::STAFF_DISABLE),
            new PermissionDefinitionDto(self::STAFF_VIEW),
            // Roles are store-neutral; editing a held role still needs every holder's stores (§1.5).
            new PermissionDefinitionDto(self::ROLE_MANAGE, kind: $storeFree),
            new PermissionDefinitionDto(self::CUSTOMER_VIEW),
            new PermissionDefinitionDto(self::CUSTOMER_BLOCK),
            new PermissionDefinitionDto(self::CUSTOMER_DELETE),
            new PermissionDefinitionDto(self::ADDRESS_FORMAT_UPDATE),
            new PermissionDefinitionDto(self::SETTINGS_UPDATE),

            new PermissionDefinitionDto(self::SUPER_ADMIN_MANAGE, reserved: true, kind: $storeFree),
            new PermissionDefinitionDto(self::ACCOUNT_ANONYMIZE, reserved: true, kind: $storeFree),
        ];
    }

    /**
     * The management actions: only an admin role may hold them, so staff manage no roles and no
     * people (owner's decision, 2026-09-19). Viewing staff is not one of them.
     *
     * @return list<string>
     */
    public static function adminOnly(): array
    {
        return [
            self::STAFF_INVITE, self::STAFF_UPDATE, self::STAFF_ASSIGN_ROLE, self::STAFF_DISABLE, self::ROLE_MANAGE,
            // Blocking and deleting reach a person's account (owner, 2026-09-20; amendment 43).
            self::CUSTOMER_BLOCK, self::CUSTOMER_DELETE,
        ];
    }

    /**
     * Actions a signed link proves on its own (amendment 38): they are declared for guests, and a
     * customer holds them too — otherwise someone who opens their own link in a browser where they
     * are already signed in would be refused, which is what the amendment set out to prevent.
     *
     * @return list<string>
     */
    public static function linkProved(): array
    {
        return [self::ACCOUNT_VERIFY_EMAIL];
    }
}

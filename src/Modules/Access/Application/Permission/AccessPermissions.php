<?php

declare(strict_types=1);

namespace Modules\Access\Application\Permission;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;

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

        return [
            new PermissionDefinitionDto(self::ACCOUNT_REGISTER, $guest),
            new PermissionDefinitionDto(self::SESSION_SIGN_IN, $guest),
            new PermissionDefinitionDto(self::SESSION_RESET_PASSWORD, $guest),
            new PermissionDefinitionDto(self::STAFF_ACCEPT_INVITATION, $guest),

            new PermissionDefinitionDto(self::ACCOUNT_VERIFY, $customer),
            new PermissionDefinitionDto(self::ACCOUNT_UPDATE, $customer),
            new PermissionDefinitionDto(self::ACCOUNT_DELETE, $customer),
            new PermissionDefinitionDto(self::ADDRESS_MANAGE, $customer),
            new PermissionDefinitionDto(self::SESSION_SIGN_OUT, $customer),

            new PermissionDefinitionDto(self::OWN_ACCOUNT_UPDATE, $staff),

            new PermissionDefinitionDto(self::STAFF_INVITE),
            new PermissionDefinitionDto(self::STAFF_UPDATE),
            new PermissionDefinitionDto(self::STAFF_ASSIGN_ROLE),
            new PermissionDefinitionDto(self::STAFF_DISABLE),
            new PermissionDefinitionDto(self::STAFF_VIEW),
            new PermissionDefinitionDto(self::ROLE_MANAGE),
            new PermissionDefinitionDto(self::CUSTOMER_VIEW),
            new PermissionDefinitionDto(self::CUSTOMER_BLOCK),
            new PermissionDefinitionDto(self::CUSTOMER_DELETE),
            new PermissionDefinitionDto(self::ADDRESS_FORMAT_UPDATE),
            new PermissionDefinitionDto(self::SETTINGS_UPDATE),

            new PermissionDefinitionDto(self::SUPER_ADMIN_MANAGE, reserved: true),
            new PermissionDefinitionDto(self::ACCOUNT_ANONYMIZE, reserved: true),
        ];
    }
}

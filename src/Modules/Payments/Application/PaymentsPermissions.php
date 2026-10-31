<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * Payments' permissions (payments.md §3), each per store. The gateway is a Super Admin's only (owner,
 * 2026-10-19 P11, P13); the bank account an admin's (accepted, 2026-10-28); the payments list a plain
 * role permission in the role editor's Payments group (access.md amendment 78).
 */
final class PaymentsPermissions
{
    /** Set up the store's gateway: its keys, test or live, on or off, Apple Pay. Super Admins only. */
    public const string GATEWAY_MANAGE = 'payments.gateway.manage';

    /** The store's bank account for transfers (moved from B2B, b2b.md amendment 33). */
    public const string BANK_ACCOUNT_UPDATE = 'payments.bank_account.update';

    /** The store's online payments, their attempts and calls; "Ask the gateway now". */
    public const string VIEW = 'payments.view';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new PermissionDefinitionDto(self::GATEWAY_MANAGE, PermissionAudience::Role, reserved: true, kind: PermissionKind::PerStore),
            new PermissionDefinitionDto(self::BANK_ACCOUNT_UPDATE, PermissionAudience::Role, adminOnly: true, kind: PermissionKind::PerStore, group: PermissionGroup::Payments),
            new PermissionDefinitionDto(self::VIEW, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Payments),
        ];
    }
}

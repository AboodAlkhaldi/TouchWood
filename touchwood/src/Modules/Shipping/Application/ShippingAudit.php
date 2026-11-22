<?php

declare(strict_types=1);

namespace Modules\Shipping\Application;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Shipping's audit entries (§3): "shipping.{subject}.{action}", the attributes that changed, both
 * languages' names among them.
 */
final class ShippingAudit
{
    private const array PERSONAL = ['phone'];

    /**
     * @param  array<string, string|null>  $from
     * @param  array<string, string|null>  $to
     */
    public static function entry(string $subject, string $action, string $id, string $storeId, array $from, array $to): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach (array_unique([...array_keys($from), ...array_keys($to)]) as $attribute) {
            if (($from[$attribute] ?? null) === ($to[$attribute] ?? null)) {
                continue;
            }

            // A phone is kept as "changed" only, as every module's: the audit log is kept forever.
            $changes = in_array($attribute, self::PERSONAL, true)
                ? $changes->personal($attribute)
                : $changes->changed($attribute, $from[$attribute] ?? null, $to[$attribute] ?? null);
        }

        return new AuditEntryDto("shipping.{$subject}.{$action}", "shipping.{$subject}", $id, $storeId, $changes);
    }
}

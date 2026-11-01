<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Payments' audit entries (§3): "payments.{subject}.{action}", the attributes that changed. A key or a
 * secret is never in one - only "replaced" and the key's last 6 characters.
 */
final class PaymentsAudit
{
    /**
     * @param  array<string, string|null>  $from
     * @param  array<string, string|null>  $to
     */
    public static function entry(string $subject, string $action, string $id, string $storeId, array $from, array $to): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach (array_unique([...array_keys($from), ...array_keys($to)]) as $attribute) {
            if (($from[$attribute] ?? null) !== ($to[$attribute] ?? null)) {
                $changes->changed($attribute, $from[$attribute] ?? null, $to[$attribute] ?? null);
            }
        }

        return new AuditEntryDto("payments.{$subject}.{$action}", "payments.{$subject}", $id, $storeId, $changes);
    }

    public static function keyChanged(string $storeId, PaymentMode $mode, ?string $last6Before, string $last6, ?string $expiresBefore, string $expiresOn): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('mode', null, $mode->value)
            ->changed('key', null, 'replaced')
            ->changed('secret', null, 'replaced')
            ->changed('key_last6', $last6Before, $last6);

        if ($expiresBefore !== $expiresOn) {
            $changes->changed('key_expires_on', $expiresBefore, $expiresOn);
        }

        return new AuditEntryDto('payments.gateway.key_changed', 'payments.gateway', $storeId, $storeId, $changes);
    }
}

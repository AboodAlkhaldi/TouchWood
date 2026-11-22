<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\StaffAlertRecipientDto;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Ops\Public\Enums\MessageKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who can open an alert's link, where the page asks another permission than the one that reaches them (ops.md §1.6). S6
 * reaches the holders of `sales.payment.record` and opens the order, under `sales.order.view`; a role may hold the first
 * alone, and then the words go with no link rather than one answering 403. S7 and S7b reach the same holders and open the
 * attempt, under `payments.payment.view`.
 *
 * `AccessApi::staffToAlert` is the one public read that names a permission's holders in a store. It filters nobody by the
 * topic's switches, so the topic here only fills the call.
 */
final readonly class AlertLinks
{
    public function __construct(private AccessApi $access) {}

    /**
     * @return list<string>|null the staff in the store who can open this message's link; null when whoever it reaches can
     */
    public function openers(EventMessage $message, StoreId $store): ?array
    {
        $permission = match ($message->kind) {
            MessageKind::TransferOverdue => 'sales.order.view',
            MessageKind::PaymentNotAccepted, MessageKind::LatePaymentRefundDue => 'payments.payment.view',
            default => null,
        };

        if ($permission === null) {
            return null;
        }

        return array_map(static fn (StaffAlertRecipientDto $staff): string => strtolower($staff->staffId), $this->access->staffToAlert($store, StaffNotificationTopic::PaymentsToCheck, $permission));
    }

    /** @param  list<string>|null  $openers  from `openers` */
    public static function opens(?array $openers, string $staffId): bool
    {
        return $openers === null || in_array(strtolower($staffId), $openers, true);
    }
}

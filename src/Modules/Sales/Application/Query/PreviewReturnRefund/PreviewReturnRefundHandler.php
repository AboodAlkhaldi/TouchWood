<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\PreviewReturnRefund;

use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReturnReads;
use Modules\Sales\Application\Return\ReturnAccess;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Returns;
use Modules\Sales\Domain\Service\ReturnRefunds;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **What completing a received return would refund**, written nowhere (sales.md §3.2; the screens' Complete
 * confirmation, owner R2-40): `ReturnRefunds` on the same facts `CompleteReturn` reads, so what is shown is
 * what is refunded, while nothing changes meanwhile. Only a received return; for staff who manage returns.
 */
final readonly class PreviewReturnRefundHandler
{
    public const string PERMISSION = SalesPermissions::RETURN_MANAGE;

    public function __construct(
        private ReturnAccess $access,
        private StaffReturnReads $reads,
        private Returns $returns,
        private StaffReader $reader,
    ) {}

    /**
     * @throws Unauthorized|ReturnNotFound|InvalidOrderChange
     */
    public function handle(PreviewReturnRefund $query): ReturnRefundPreview
    {
        [$returnId] = $this->access->authorize(self::PERMISSION, $query->returnId);
        $return = $this->reads->returnOf($returnId) ?? throw new ReturnNotFound;

        if ($return['status'] !== ReturnState::RECEIVED) {
            throw new InvalidOrderChange;
        }

        $currency = (string) $return['currency_code'];
        $facts = $this->returns->forRefund((string) $return['order_id']);
        $received = array_map(static fn (array $line): int => $line['received'] ?? 0, $this->returns->lines($returnId));
        $refund = ReturnRefunds::of($facts['lines'], $facts['before'], $received, $facts['shippingRefunded'], $facts['shippingFee'], $facts['shippingVat']);
        [$shipping, $shippingVat] = $refund['shipping'] ? [$facts['shippingFee'], $facts['shippingVat']] : [0, 0];
        $money = static fn (int $minor): Money => Money::of($minor, $currency);

        return new ReturnRefundPreview(
            $money($refund['goods']), $money($refund['refund'] - $refund['goods'] - $shipping - $shippingVat), $money($shipping), $money($shippingVat),
            $money($refund['refund']), $this->reader->exponent($currency),
        );
    }
}

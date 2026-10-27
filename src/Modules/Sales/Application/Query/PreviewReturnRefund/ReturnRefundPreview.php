<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\PreviewReturnRefund;

use Shared\Domain\ValueObject\Money;

/**
 * What completing a received return would refund (sales.md §1.8, §3.2): the pieces' goods, their share of
 * the VAT, and the shipping with its VAT when it is the return that makes every bought piece returned;
 * `total` their sum, what `CompleteReturn` answers.
 */
final readonly class ReturnRefundPreview
{
    public function __construct(
        public Money $goods,
        public Money $vat,
        public Money $shipping,
        public Money $shippingVat,
        public Money $total,
        public int $exponent,
    ) {}
}

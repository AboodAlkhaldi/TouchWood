<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** A return asked and not finished, with the goods it would refund (`RevenueDayDto::$waiting`). No customer. */
final readonly class WaitingRefundDto
{
    public function __construct(
        public string $returnId,
        public string $orderNumber,
        /** The goods it would refund, without VAT and shipping: the pieces received, or else those asked. */
        public Money $estimate,
    ) {}
}

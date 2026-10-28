<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** One line of an order as Ops' message shows it (`SalesReports::orderForMessage`): as sold, never today's. */
final readonly class OrderMessageLineDto
{
    /**
     * @param  list<array{attribute: string, value: string}>  $values  the size's values, in the language asked
     */
    public function __construct(
        /** The product's name as sold, in the language asked (the Arabic one where it had no English). */
        public string $name,
        public array $values,
        public int $quantity,
        /** The line's total as sold, before the coupon and the points; 0 for the gift. */
        public Money $total,
        public bool $gift,
    ) {}
}

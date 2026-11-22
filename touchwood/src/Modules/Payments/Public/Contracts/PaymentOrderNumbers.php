<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Contracts;

/**
 * The numbers of the orders behind attempts, for the Payments list (payments.md §4.2). Payments takes an order id and
 * nothing more, and depends on nothing of Sales, so Sales answers this.
 */
interface PaymentOrderNumbers
{
    /**
     * @param  list<string>  $orderIds
     * @return array<string, string> order id => its number; an id with no order (a try not paid) left out
     */
    public function numbers(array $orderIds): array;
}

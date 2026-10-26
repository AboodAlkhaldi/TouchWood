<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use Shared\Domain\ValueObject\StoreId;

/** Each store's own order numbers, from 1 (sales.md §1.3; answer 35), shown four digits at least: `SA-0001`. */
interface OrderNumbers
{
    /** The store's next number; a number taken by a placement that then fails is never given again. */
    public function next(StoreId $store): int;

    /** The store's numbers made, starting at 1, when they are not already. */
    public function create(StoreId $store): void;
}

<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A removal larger than what is in stock (inventory.md §1.5, §7): stock may fall below what is held -
 * those orders are flagged - but never below 0.
 *
 * *(Stage 5's screens, Q2:)* a removal by hand names **its size** (`variants`) and what is in stock, and
 * its words name the box: "Quantity can't be more than 4, what's in stock." (§4.3.1, the `removal` rule).
 */
final class StockBelowZero extends InventoryError
{
    public const string REMOVAL = 'removal';

    /**
     * @param  list<string>  $variants  the sizes it is refused for
     * @param  int|null  $inStock  what is in stock, for a removal's words
     */
    public function __construct(public readonly array $variants = [], public readonly ?int $inStock = null)
    {
        parent::__construct('That would take the stock below 0.');
    }

    public function type(): string
    {
        return 'inventory.stock_below_zero';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        $context = $this->variants === [] ? [] : ['variants' => implode(',', $this->variants)];

        return $this->inStock === null ? $context : [...$context, 'rule' => self::REMOVAL, 'in_stock' => $this->inStock];
    }
}

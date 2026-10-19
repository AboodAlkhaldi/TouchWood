<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A quantity below 1, or a stocktake below 0 (inventory.md §7).
 *
 * *(Stage 5's screens, Q2:)* a refusal from a screen names **the sizes** it is about (`variants`), so the
 * panel marks their rows, and **the rule** its words say, naming the box (§4.3.1): `count` - "Count must
 * be a whole number from 0 to 2,147,483,647." - `quantity` - "Quantity must be 1 or more." - or
 * `too_many`, a change that would take the stock above what the column keeps. Without a rule, the one
 * detail of §7.
 */
final class QuantityInvalid extends InventoryError
{
    public const string COUNT = 'count';

    public const string QUANTITY = 'quantity';

    public const string TOO_MANY = 'too_many';

    /**
     * @param  list<string>  $variants  the sizes it is refused for
     * @param  self::COUNT|self::QUANTITY|self::TOO_MANY|null  $rule
     */
    public function __construct(public readonly array $variants = [], public readonly ?string $rule = null)
    {
        parent::__construct('A quantity must be at least 1, a count at least 0, and neither more than 2,147,483,647 pieces.');
    }

    public function type(): string
    {
        return 'inventory.quantity_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return array_filter(
            ['variants' => implode(',', $this->variants), 'rule' => $this->rule],
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }
}

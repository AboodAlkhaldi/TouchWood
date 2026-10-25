<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Modules\Sales\Public\Enums\CartLineProblem;
use Shared\Domain\ValueObject\Money;

/**
 * A line of the cart as the shopper sees it now (sales.md §1.1): the size's card (name in the page's language,
 * the product's address, its photo), its code and values, the mode and pieces, **today's prices** (null when
 * it has none), and whether it can be ordered as it is — `problem` null when it can — with its limits.
 */
final readonly class CartLineView
{
    /**
     * @param  array<string, array<string, string>>|null  $photo  size slug => format => URL
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $values
     */
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $name,
        public ?string $slug,
        public ?array $photo,
        public string $code,
        public array $values,
        public string $mode,
        public int $quantity,
        public ?Money $unit,
        public ?Money $listUnit,
        public ?Money $total,
        public ?CartLineProblem $problem,
        public int $minimum,
        public ?int $maximum,
    ) {}
}

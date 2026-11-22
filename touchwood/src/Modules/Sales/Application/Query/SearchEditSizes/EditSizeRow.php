<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\SearchEditSizes;

use Modules\Pricing\Public\Dto\WholesaleBandDto;
use Shared\Domain\ValueObject\Money;

/**
 * A size the order edit may add (sales.md §3.2; the screens' B7): its product, code, values and photo; today's
 * one-piece retail price — `now`, and `before` only while `now` is lower — when it sells retail and is
 * priced; when it sells wholesale, the price at its wholesale minimum and its bands; how many may be
 * ordered now (null: not counted, the provider's).
 */
final readonly class EditSizeRow
{
    /**
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $values
     * @param  array{now: Money, before: ?Money}|null  $retail
     * @param  array{now: Money, before: ?Money, minimum: int, bands: list<WholesaleBandDto>}|null  $wholesale
     */
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $productNameAr,
        public string $productNameEn,
        public string $code,
        public array $values,
        public ?string $photoMediaId,
        public ?array $retail,
        public ?array $wholesale,
        public ?int $available,
        public int $exponent,
    ) {}
}

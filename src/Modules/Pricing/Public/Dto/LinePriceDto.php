<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use DateTimeImmutable;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;

/**
 * A priced line (pricing.md §2.2). `listUnit` is the base price of one piece - what `gross_subtotal`
 * counts - and `unit` what one piece costs on this line, the lowest that applies (§1.4); `kind` says
 * which won. `endsAt` is when that price stops applying (the end of its sale, campaign or discount),
 * null when it has no end.
 */
final readonly class LinePriceDto
{
    public function __construct(
        public string $variantId,
        public SaleMode $mode,
        public int $quantity,
        public Money $listUnit,
        public Money $unit,
        public PriceKind $kind,
        public Money $listTotal,
        public Money $total,
        public ?DateTimeImmutable $endsAt,
    ) {}
}

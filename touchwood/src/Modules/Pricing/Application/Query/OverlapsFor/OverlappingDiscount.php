<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * A category discount reaching the same product in the same dates: a percentage (in basis points) or an
 * amount (in minor units), its lines, its dates as ISO 8601, and its short description in the reader's
 * language ("Kitchens: 10% off"; stage 5's screens step 2).
 */
final readonly class OverlappingDiscount
{
    public function __construct(
        public string $id,
        public string $categoryId,
        public ?int $percentBasisPoints,
        public ?int $amountMinor,
        public ?string $currencyCode,
        public string $lines,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public ?string $description = null,
    ) {}
}

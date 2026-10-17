<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * A category discount reaching the same product in the same dates: a percentage (in basis points) or an
 * amount (in minor units), its lines, its dates as ISO 8601.
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
    ) {}
}

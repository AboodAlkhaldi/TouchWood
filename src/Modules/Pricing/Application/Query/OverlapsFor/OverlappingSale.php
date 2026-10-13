<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * Another sale on some of the same sizes in the same dates: a percentage (in basis points) or a price
 * (in minor units), its dates as ISO 8601, and the sizes it shares with the sale being saved.
 */
final readonly class OverlappingSale
{
    /**
     * @param  list<string>  $sharedVariantIds
     */
    public function __construct(
        public string $id,
        public ?int $percentBasisPoints,
        public ?int $amountMinor,
        public ?string $currencyCode,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public array $sharedVariantIds,
    ) {}
}

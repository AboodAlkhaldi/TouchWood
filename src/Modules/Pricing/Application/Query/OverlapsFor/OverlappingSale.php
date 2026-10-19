<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * Another sale on some of the same sizes in the same dates: a percentage (in basis points) or a price
 * (in minor units), its dates as ISO 8601, the sizes it shares with the sale being saved, and its short
 * description in the reader's language ("−10% · 60 cm, 80 cm"; stage 5's screens step 2).
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
        public ?string $description = null,
    ) {}
}

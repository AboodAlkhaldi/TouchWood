<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

/**
 * **A sale or a category discount named in a line** (pricing.md §2.1, §4.3.3; for Sync's report): its id,
 * whether it still exists, and - while it does - its short description in the reader's language: a sale
 * "−10% · 60 cm, 80 cm" (its percentage or its price, then its sizes), a discount "Kitchens: 50.00 off" with the currency's sign
 * (its category, then what it takes off). A sale or discount gone has no description: "Sale removed".
 */
final readonly class OfferNameDto
{
    public function __construct(
        public string $id,
        public bool $exists,
        public ?string $description,
    ) {}
}

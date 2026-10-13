<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\ChangeSale;

/**
 * A sale as the form holds it now (pricing.md §3, §4.1): a scheduled one changed in full; a running one
 * only its end - the rest as it is.
 */
final readonly class ChangeSale
{
    public function __construct(
        public string $saleId,
        public ?string $percent,
        public ?string $price,
        public ?string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins = false,
    ) {}
}

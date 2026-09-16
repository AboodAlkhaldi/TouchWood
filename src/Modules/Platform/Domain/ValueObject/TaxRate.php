<?php

namespace Modules\Platform\Domain\ValueObject;

use Modules\Platform\Domain\Exception\InvalidTaxRate;

/**
 * A store's fixed tax percentage, held as basis points so no float or DECIMAL is involved:
 * 15% is 1500 (handoff §10.3).
 */
final readonly class TaxRate
{
    private function __construct(
        public int $basisPoints,
    ) {}

    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new InvalidTaxRate($basisPoints);
        }

        return new self($basisPoints);
    }
}

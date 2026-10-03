<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * How long a warranty lasts: a number of months, 1 to 600 (fifty years), or for life (catalog.md
 * §1.9, §9.3 #2).
 */
final readonly class WarrantyPeriod
{
    public const int MAX_MONTHS = 600;

    private function __construct(
        public ?int $months,
    ) {}

    /**
     * @param  int|null  $months  null for life
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(?int $months): self
    {
        if ($months !== null && ($months < 1 || $months > self::MAX_MONTHS)) {
            throw new InvalidCatalogAttribute('period_months', 'between 1 and '.self::MAX_MONTHS.' months, or for life');
        }

        return new self($months);
    }

    public function isLifetime(): bool
    {
        return $this->months === null;
    }
}

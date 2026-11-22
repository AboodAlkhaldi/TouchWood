<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * A variant's physical facts (catalog.md §1.2, §9.3 #9): optional now, read by Shipping later —
 * whole grams and whole millimetres, each 1 to 1,000,000.
 */
final readonly class VariantMeasures
{
    public const int MAX = 1_000_000;

    private function __construct(
        public ?int $weightGrams,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
    ) {}

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function of(?int $weightGrams = null, ?int $lengthMm = null, ?int $widthMm = null, ?int $heightMm = null): self
    {
        return new self(
            self::check('weight_grams', $weightGrams),
            self::check('length_mm', $lengthMm),
            self::check('width_mm', $widthMm),
            self::check('height_mm', $heightMm),
        );
    }

    public static function reconstitute(?int $weightGrams, ?int $lengthMm, ?int $widthMm, ?int $heightMm): self
    {
        return new self($weightGrams, $lengthMm, $widthMm, $heightMm);
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function check(string $attribute, ?int $value): ?int
    {
        if ($value !== null && ($value < 1 || $value > self::MAX)) {
            throw new InvalidCatalogAttribute($attribute, 'a whole number from 1 to '.self::MAX);
        }

        return $value;
    }
}

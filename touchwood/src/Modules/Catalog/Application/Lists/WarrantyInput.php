<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Model\Warranty;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Domain\ValueObject\WarrantyPeriod;

/**
 * A warranty's form, as both adding and editing take it, turned into the values the model holds.
 */
final readonly class WarrantyInput
{
    private function __construct(
        public LocalizedName $name,
        public StructuredText $termsAr,
        public StructuredText $termsEn,
        public WarrantyPeriod $period,
    ) {}

    /**
     * @param  array<array-key, mixed>  $termsAr
     * @param  array<array-key, mixed>  $termsEn
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $nameAr, string $nameEn, array $termsAr, array $termsEn, ?int $periodMonths): self
    {
        return new self(
            LocalizedName::of($nameAr, $nameEn, Warranty::NAME_MAX),
            StructuredText::of('terms_ar', $termsAr, Warranty::TERMS_MAX),
            StructuredText::of('terms_en', $termsEn, Warranty::TERMS_MAX),
            WarrantyPeriod::of($periodMonths),
        );
    }
}

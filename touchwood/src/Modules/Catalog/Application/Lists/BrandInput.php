<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Model\Brand;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\Slugs;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Public\Enums\AgencyType;

/**
 * A brand's form, as both adding and editing take it, turned into the values the model holds — and
 * the one check that reads the other rows: neither slug held, now or ever, by another brand.
 */
final readonly class BrandInput
{
    public function __construct(
        public LocalizedName $name,
        public Slugs $slugs,
        public ?StructuredText $descriptionAr,
        public ?StructuredText $descriptionEn,
        public AgencyType $agencyType,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $descriptionAr
     * @param  array<array-key, mixed>|null  $descriptionEn
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $nameAr, string $nameEn, ?string $slugAr, ?string $slugEn, ?array $descriptionAr, ?array $descriptionEn, string $agencyType): self
    {
        $name = LocalizedName::of($nameAr, $nameEn, Brand::NAME_MAX);

        return new self(
            $name,
            Slugs::for($name, $slugAr, $slugEn),
            $descriptionAr === null ? null : StructuredText::of('description_ar', $descriptionAr, Brand::DESCRIPTION_MAX),
            $descriptionEn === null ? null : StructuredText::of('description_en', $descriptionEn, Brand::DESCRIPTION_MAX),
            AgencyType::tryFrom($agencyType) ?? throw new InvalidCatalogAttribute('agency_type', 'house, exclusive agent or distributor'),
        );
    }

    /**
     * @throws SlugTaken
     */
    public function requireFreeSlugs(BrandRepository $brands, ?string $exceptBrandId = null): void
    {
        foreach (['ar' => $this->slugs->ar->value, 'en' => $this->slugs->en->value] as $locale => $slug) {
            if ($brands->slugTaken($locale, $slug, $exceptBrandId)) {
                throw new SlugTaken($slug);
            }
        }
    }
}

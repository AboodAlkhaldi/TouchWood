<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Public\Enums\AgencyType;

/**
 * The one brand the seed creates (catalog.md §1.6, amendment 1(c), owner 2026-10-03): TouchWood, the
 * house brand, the default — every other brand, and every category, comes from staff or the import.
 * A seeder may name a brand; Domain/ and Application/ never do (handoff §2 rule 2).
 *
 * Run twice, it adds nothing: a brand already holding its English slug is left as it is.
 */
final class CatalogSeeder extends Seeder
{
    private const string SLUG_EN = 'touchwood';

    public function run(BrandRepository $brands, AddBrandHandler $add): void
    {
        if ($brands->slugTaken('en', self::SLUG_EN)) {
            return;
        }

        // Added where no brand is the default yet, it becomes the default (AddBrandHandler).
        $add->handle(new AddBrand(
            nameAr: 'تاتش وود',
            nameEn: 'TouchWood',
            agencyType: AgencyType::House->value,
            showInDefaultListings: true,
            position: 0,
            slugEn: self::SLUG_EN,
            originCountry: 'SA',
        ));
    }
}

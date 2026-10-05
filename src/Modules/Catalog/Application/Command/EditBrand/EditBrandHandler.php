<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditBrand;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\BrandInput;
use Modules\Catalog\Application\Lists\CatalogImages;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Editing a brand** (catalog.md §1.6), under `catalog.brand.manage` with All stores. What did not
 * change is not written to the audit log, and an edit that changes nothing records nothing.
 * **Whether it shows in default listings** is copied into its products' listing rows (handoff §9.4),
 * so the edit takes the products' lock first, as every change to them.
 */
final readonly class EditBrandHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private BrandRepository $brands,
        private CatalogImages $images,
        private ProductRepository $products,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws BrandNotFound|InvalidCatalogAttribute|SlugTaken|Unauthorized
     */
    public function handle(EditBrand $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $input = BrandInput::of($command->nameAr, $command->nameEn, $command->slugAr, $command->slugEn, $command->descriptionAr, $command->descriptionEn, $command->agencyType);

        $this->change->runAfterProducts(ListLocks::BRANDS, function () use ($input, $command): array {
            // Inside, so a retried attempt asks again: the file may have been deleted meanwhile.
            $logo = $this->images->check('logo_media_id', $command->logoMediaId);
            $brand = $this->brands->byId($command->brandId) ?? throw new BrandNotFound($command->brandId);
            $input->requireFreeSlugs($this->brands, $brand->id());

            $shown = $brand->showInDefaultListings();
            $brand->edit($input->name, $input->slugs, $input->descriptionAr, $input->descriptionEn, $logo, $command->originCountry, $input->agencyType, $command->showInDefaultListings, $command->position);
            $entry = ListAudit::changed('brand', 'edited', $brand->id(), $brand->pullChanges(), $brand->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->brands->update($brand);

            // Copied into its products' rows, so the default grid needs no join (handoff §9.4).
            if ($brand->showInDefaultListings() !== $shown) {
                $this->listingRows->refresh($this->products->idsWithBrand($brand->id()));
            }

            return [null, [$entry]];
        });
    }
}

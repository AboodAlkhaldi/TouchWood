<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddBrand;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\BrandInput;
use Modules\Catalog\Application\Lists\CatalogImages;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Model\Brand;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Adding a brand** (catalog.md §1.6), under `catalog.brand.manage` with All stores. Its slugs are
 * free of every brand's, now and before.
 *
 * Exactly one brand is the default at any time (§1.6): the seed makes TouchWood the default; should a
 * brand be added where there is none yet, it becomes the default itself.
 */
final readonly class AddBrandHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private BrandRepository $brands,
        private CatalogImages $images,
    ) {}

    /**
     * @return string the new brand's id
     *
     * @throws InvalidCatalogAttribute|SlugTaken|Unauthorized
     */
    public function handle(AddBrand $command): string
    {
        $this->change->authorize(self::PERMISSION);
        $input = BrandInput::of($command->nameAr, $command->nameEn, $command->slugAr, $command->slugEn, $command->descriptionAr, $command->descriptionEn, $command->agencyType);
        $id = $this->brands->nextId();

        return $this->change->run(ListLocks::BRANDS, function () use ($id, $input, $command): array {
            // Inside, so a retried attempt asks again: the file may have been deleted meanwhile.
            $logo = $this->images->check('logo_media_id', $command->logoMediaId);
            $input->requireFreeSlugs($this->brands);

            $brand = Brand::add($id, $input->name, $input->slugs, $input->descriptionAr, $input->descriptionEn, $logo, $command->originCountry, $input->agencyType, $command->showInDefaultListings, $command->position);

            if ($this->brands->defaultBrand() === null) {
                $brand->makeDefault();
            }

            $this->brands->add($brand);
            $brand->pullChanges();

            return [$id, [ListAudit::added('brand', $id, $brand->snapshot())]];
        });
    }
}

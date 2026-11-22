<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedSlugs;

use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\ValueObject\ProductName;
use Modules\Catalog\Domain\ValueObject\ProductSlugs;
use Shared\Application\Unauthorized;

/**
 * **A product's own web address** (catalog.md §1.12, amendment 8(c)): given on the import's page for a
 * product whose address would collide — with another of the file, or with one the catalog has or had.
 * In the shape the panel's form takes (§1.1); an English one only with the English name. Whether it is
 * free is asked again by the confirm, which counts what still collides.
 */
final readonly class SetImportedSlugsHandler
{
    public const string PERMISSION = ImportedProductsChange::PERMISSION;

    public function __construct(
        private ImportedProductsChange $change,
    ) {}

    /**
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(SetImportedSlugs $command): void
    {
        $this->change->authorize();

        if (self::blank($command->slugAr) && self::blank($command->slugEn)) {
            throw new InvalidCatalogAttribute('slug_ar', 'an address in Arabic, English, or both');
        }

        $this->change->run($command->importId, [$command->productId], 'slugs', trim(($command->slugAr ?? '').' '.($command->slugEn ?? '')), ImportedProductsChange::REPLACE, static function (FileProduct $product) use ($command): FileProduct {
            $slugs = ProductSlugs::for(
                ProductName::reconstitute($product->nameAr, $product->nameEn),
                self::blank($command->slugAr) ? $product->slugAr : $command->slugAr,
                self::blank($command->slugEn) ? $product->slugEn : $command->slugEn,
            );

            return $product->with([
                'slug_ar' => self::blank($command->slugAr) ? $product->slugAr : $slugs->ar->value,
                'slug_en' => self::blank($command->slugEn) ? $product->slugEn : $slugs->en?->value,
            ]);
        });
    }

    private static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}

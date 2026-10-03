<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\ProductName;
use Modules\Catalog\Domain\ValueObject\ProductSlugs;
use Modules\Catalog\Domain\ValueObject\StructuredText;

/**
 * What creating and editing a product share: its names and slugs as the form sends them, its
 * descriptions, and the one check that reads the other rows — neither slug held, now or ever, by
 * another product (catalog.md §1.1).
 */
final readonly class ProductInput
{
    public function __construct(
        private ProductRepository $products,
    ) {}

    /**
     * @return array{ProductName, ProductSlugs}
     *
     * @throws InvalidCatalogAttribute
     */
    public static function names(string $nameAr, ?string $nameEn, ?string $slugAr, ?string $slugEn): array
    {
        $name = ProductName::of($nameAr, $nameEn);

        return [$name, ProductSlugs::for($name, $slugAr, $slugEn)];
    }

    /**
     * @param  array<array-key, mixed>|null  $document  the structured text, decoded
     *
     * @throws InvalidCatalogAttribute
     */
    public static function description(string $attribute, ?array $document): ?StructuredText
    {
        return $document === null ? null : StructuredText::of($attribute, $document, Product::DESCRIPTION_MAX);
    }

    /**
     * @throws SlugTaken
     */
    public function requireFreeSlugs(ProductSlugs $slugs, ?string $exceptProductId = null): void
    {
        foreach ($slugs->byLocale() as $locale => $slug) {
            if ($slug !== null && $this->products->slugTaken($locale, $slug, $exceptProductId)) {
                throw new SlugTaken($slug);
            }
        }
    }
}

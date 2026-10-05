<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\ProductName;
use Modules\Catalog\Domain\ValueObject\ProductSlugs;
use Modules\Catalog\Domain\ValueObject\Slugs;

/**
 * **Web addresses that would collide** (catalog.md §1.12, amendment 8(c)): an address is one product's
 * or one category's, now and ever (§1.1, §5.3). Before bringing in, the import's products' addresses —
 * the file's, the page's, or made from the name as a product's form makes them — are compared with the
 * catalog's and with each other's; a new category's with the catalog's and the other new ones'. What
 * collides is decided on the page: an address of its own.
 */
final readonly class ImportAddresses
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
    ) {}

    /**
     * The import's products whose address would be taken, and in which languages: by a catalog
     * product, now or once — the product it updates or replaces aside —, or by another product
     * coming in. A product skipped, or updated without an address of its own, keeps its own.
     *
     * @param  list<ImportProduct>  $rows
     * @return array<string, list<string>> import product id => the languages whose address is taken
     */
    public function taken(array $rows): array
    {
        $addresses = [];
        $taken = [];

        foreach ($rows as $row) {
            $product = $row->effective();
            $keeps = $row->decision === ImportProduct::UPDATE && $product->slugAr === null && $product->slugEn === null;

            if ($row->state !== 'WAITING' || $row->decision === ImportProduct::SKIP || $keeps) {
                continue;
            }

            try {
                $slugs = ProductSlugs::for(ProductName::reconstitute($product->nameAr, $product->nameEn), $product->slugAr, $product->slugEn);
            } catch (InvalidCatalogAttribute $error) {
                // A name nothing of which can stand in an address: one must be given.
                $taken[$row->id][] = $error->attribute === 'slug_en' ? 'en' : 'ar';

                continue;
            }

            $except = in_array($row->decision, [ImportProduct::UPDATE, ImportProduct::REPLACE], true) ? $row->conflictProductId : null;

            foreach (['ar' => $slugs->ar->value, 'en' => $slugs->en?->value] as $locale => $slug) {
                if ($slug === null) {
                    continue;
                }

                $addresses[$locale][$slug][] = $row->id;

                if ($this->products->slugTaken($locale, $slug, $except)) {
                    $taken[$row->id][] = $locale;
                }
            }
        }

        foreach ($addresses as $locale => $bySlug) {
            foreach ($bySlug as $ids) {
                foreach (count($ids) > 1 ? $ids : [] as $id) {
                    $taken[$id][] = $locale;
                }
            }
        }

        return array_map(static fn (array $locales): array => array_values(array_unique($locales)), $taken);
    }

    /**
     * A new category's addresses — its own, or made from its names —, unless another category has
     * one, now or once, or another new category of the import does.
     *
     * @param  list<ImportName>  $others  the import's other new categories
     * @return array{string, string} its addresses, Arabic and English
     *
     * @throws InvalidCatalogAttribute naming the language whose address is taken
     */
    public function freeCategory(string $nameAr, string $nameEn, ?string $slugAr, ?string $slugEn, array $others, string $at): array
    {
        $slugs = self::categorySlugs($nameAr, $nameEn, $slugAr, $slugEn, $at);

        foreach (['ar' => $slugs->ar->value, 'en' => $slugs->en->value] as $locale => $slug) {
            $other = array_filter($others, static function (ImportName $name) use ($locale, $slug, $at): bool {
                $theirs = self::categorySlugs((string) $name->nameAr, (string) $name->nameEn, $name->slugAr, $name->slugEn, $at);

                return ($locale === 'ar' ? $theirs->ar->value : $theirs->en->value) === $slug;
            });

            if ($other !== [] || $this->categories->slugTaken($locale, $slug)) {
                throw new InvalidCatalogAttribute("{$at}.slug_{$locale}", 'an address no other category has, now or before: give one');
            }
        }

        return [$slugs->ar->value, $slugs->en->value];
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function categorySlugs(string $nameAr, string $nameEn, ?string $slugAr, ?string $slugEn, string $at): Slugs
    {
        try {
            return Slugs::for(LocalizedName::of($nameAr, $nameEn, ProductsFile::NAME_MAX), $slugAr, $slugEn);
        } catch (InvalidCatalogAttribute $error) {
            throw new InvalidCatalogAttribute("{$at}.{$error->attribute}", $error->reason);
        }
    }
}

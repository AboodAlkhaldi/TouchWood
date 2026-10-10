<?php

declare(strict_types=1);

namespace Tests\Modules\Catalog\Support;

use Modules\Catalog\Application\Command\AddAttribute\AddAttribute;
use Modules\Catalog\Application\Command\AddAttribute\AddAttributeHandler;
use Modules\Catalog\Application\Command\AddAttributeSet\AddAttributeSet;
use Modules\Catalog\Application\Command\AddAttributeSet\AddAttributeSetHandler;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValue;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValueHandler;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\AddWarranty\AddWarranty;
use Modules\Catalog\Application\Command\AddWarranty\AddWarrantyHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/**
 * The rows a product test stands on — brands, categories, attributes, sets, warranties, products and
 * their variants — made through the real handlers **as the system**, so the test's own actor holds
 * only the jobs it is about. Arabic names carry a number, so each gives its own Arabic slug (§5.3:
 * Arabic letters and digits only).
 */
final class CatalogProducts
{
    private static int $next = 0;

    public static function brand(string $nameEn = 'Blum'): string
    {
        $n = self::next();

        return Fx::asSystem(fn (): string => app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "{$nameEn} {$n}", 'DISTRIBUTOR')));
    }

    public static function category(string $nameEn = 'Kitchens', ?string $parentId = null): string
    {
        $n = self::next();

        return Fx::asSystem(fn (): string => app(AddCategoryHandler::class)->handle(new AddCategory("قسم {$n}", "{$nameEn} {$n}", $parentId)));
    }

    public static function attribute(string $nameEn = 'Width', string $kind = 'VARIANT'): string
    {
        $n = self::next();

        return Fx::asSystem(fn (): string => app(AddAttributeHandler::class)->handle(new AddAttribute("خاصية {$n}", "{$nameEn} {$n}", $kind)));
    }

    public static function value(string $attributeId, string $nameEn): string
    {
        $n = self::next();

        return Fx::asSystem(fn (): string => app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($attributeId, "قيمة {$n}", $nameEn)));
    }

    /**
     * @param  list<string>  $attributeIds
     */
    public static function set(array $attributeIds, string $nameEn = 'Sizes'): string
    {
        $n = self::next();

        return Fx::asSystem(fn (): string => app(AddAttributeSetHandler::class)->handle(new AddAttributeSet("مجموعة {$n}", "{$nameEn} {$n}", $attributeIds)));
    }

    public static function warranty(): string
    {
        $n = self::next();
        $terms = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Terms']]]]];

        return Fx::asSystem(fn (): string => app(AddWarrantyHandler::class)->handle(new AddWarranty("ضمان {$n}", "Warranty {$n}", $terms, $terms, 24)));
    }

    /**
     * A draft, on the default brand unless one is given, created from the base store.
     */
    public static function product(?string $nameEn = 'Drawer', ?string $brandId = null): string
    {
        $n = self::next();
        $brandId ??= self::brand();

        return Fx::asSystem(fn (): string => app(CreateProductHandler::class)->handle(new CreateProduct("درج {$n}", $nameEn === null ? null : "{$nameEn} {$n}", $brandId)));
    }

    /**
     * @param  array<string, string>  $values  attribute id => value id
     */
    public static function variant(string $productId, string $code, array $values = []): string
    {
        return Fx::asSystem(fn (): string => app(AddVariantHandler::class)->handle(new AddVariant($productId, $code, $values)));
    }

    /**
     * A ready product — both names and descriptions, a lowest active category, a photo whose sizes are
     * ready — with one variant per size of a set of widths, each its own code.
     *
     * @param  list<string>  $sizes
     * @return array{product: string, variants: list<string>, width: string}
     */
    public static function ready(array $sizes = ['60 cm'], ?string $categoryId = null): array
    {
        $width = self::attribute('Width');
        $values = array_map(static fn (string $size): string => self::value($width, $size), $sizes);
        $set = self::set([$width]);
        $category = $categoryId ?? self::category();
        $id = self::product('Drawer');
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];

        $variants = Fx::asSystem(function () use ($id, $set, $category, $text, $width, $values): array {
            $product = app(ProductRepository::class)->find($id) ?? throw new \LogicException('No such product.');
            app(EditProductDetailsHandler::class)->handle(new EditProductDetails(
                $id, $product->name()->ar, $product->name()->en, $product->brandId(),
                descriptionAr: $text, descriptionEn: $text, categoryId: $category, attributeSetId: $set,
            ));
            $variants = array_map(fn (string $value): string => app(AddVariantHandler::class)->handle(new AddVariant($id, (string) (5_000_000 + self::next()), [$width => $value])), $values);
            app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [CatalogFixtures::media()]));
            app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));

            return $variants;
        });

        return ['product' => $id, 'variants' => $variants, 'width' => $width];
    }

    private static function next(): int
    {
        return ++self::$next;
    }
}

<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ActivateAttribute\ActivateAttribute;
use Modules\Catalog\Application\Command\ActivateAttribute\ActivateAttributeHandler;
use Modules\Catalog\Application\Command\ActivateAttributeSet\ActivateAttributeSet;
use Modules\Catalog\Application\Command\ActivateAttributeSet\ActivateAttributeSetHandler;
use Modules\Catalog\Application\Command\ActivateAttributeValue\ActivateAttributeValue;
use Modules\Catalog\Application\Command\ActivateAttributeValue\ActivateAttributeValueHandler;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrand;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrandHandler;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategory;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategoryHandler;
use Modules\Catalog\Application\Command\ActivateLabel\ActivateLabel;
use Modules\Catalog\Application\Command\ActivateLabel\ActivateLabelHandler;
use Modules\Catalog\Application\Command\ActivateWarranty\ActivateWarranty;
use Modules\Catalog\Application\Command\ActivateWarranty\ActivateWarrantyHandler;
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
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\AddWarranty\AddWarranty;
use Modules\Catalog\Application\Command\AddWarranty\AddWarrantyHandler;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPair;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPairHandler;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabels;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabelsHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\ClearNotAvailableNow\ClearNotAvailableNow;
use Modules\Catalog\Application\Command\ClearNotAvailableNow\ClearNotAvailableNowHandler;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCode;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCodeHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttribute;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttributeHandler;
use Modules\Catalog\Application\Command\DeactivateAttributeSet\DeactivateAttributeSet;
use Modules\Catalog\Application\Command\DeactivateAttributeSet\DeactivateAttributeSetHandler;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValue;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValueHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabel;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabelHandler;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarranty;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarrantyHandler;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttribute;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttributeHandler;
use Modules\Catalog\Application\Command\DeleteAttributeSet\DeleteAttributeSet;
use Modules\Catalog\Application\Command\DeleteAttributeSet\DeleteAttributeSetHandler;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValue;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValueHandler;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrand;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrandHandler;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategory;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategoryHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariant;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariantHandler;
use Modules\Catalog\Application\Command\DeleteLabel\DeleteLabel;
use Modules\Catalog\Application\Command\DeleteLabel\DeleteLabelHandler;
use Modules\Catalog\Application\Command\DeleteWarranty\DeleteWarranty;
use Modules\Catalog\Application\Command\DeleteWarranty\DeleteWarrantyHandler;
use Modules\Catalog\Application\Command\DeleteWordPair\DeleteWordPair;
use Modules\Catalog\Application\Command\DeleteWordPair\DeleteWordPairHandler;
use Modules\Catalog\Application\Command\EditAttribute\EditAttribute;
use Modules\Catalog\Application\Command\EditAttribute\EditAttributeHandler;
use Modules\Catalog\Application\Command\EditAttributeSet\EditAttributeSet;
use Modules\Catalog\Application\Command\EditAttributeSet\EditAttributeSetHandler;
use Modules\Catalog\Application\Command\EditAttributeValue\EditAttributeValue;
use Modules\Catalog\Application\Command\EditAttributeValue\EditAttributeValueHandler;
use Modules\Catalog\Application\Command\EditBrand\EditBrand;
use Modules\Catalog\Application\Command\EditBrand\EditBrandHandler;
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Application\Command\EditLabel\EditLabel;
use Modules\Catalog\Application\Command\EditLabel\EditLabelHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\EditWarranty\EditWarranty;
use Modules\Catalog\Application\Command\EditWarranty\EditWarrantyHandler;
use Modules\Catalog\Application\Command\MakeBrandDefault\MakeBrandDefault;
use Modules\Catalog\Application\Command\MakeBrandDefault\MakeBrandDefaultHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategory;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategoryHandler;
use Modules\Catalog\Application\Command\RankCategories\RankCategories;
use Modules\Catalog\Application\Command\RankCategories\RankCategoriesHandler;
use Modules\Catalog\Application\Command\RestoreProduct\RestoreProduct;
use Modules\Catalog\Application\Command\RestoreProduct\RestoreProductHandler;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariant;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariantHandler;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValues;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValuesHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWords;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWordsHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotos;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotosHandler;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariant;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariantHandler;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What every change to a shared list shares, checked for each change rather than for one of a kind
| (review of step 2): its list's lock is the first thing it does inside its own transaction; an id
| that is not in its list is answered as not found; a change that changes nothing writes nothing and
| records nothing; and what the audit log keeps reads from what was to what is.
*/

uses(RefreshDatabase::class);

const CATALOG_GUARDS_UNKNOWN = '01j8z3k4m5n6p7q8r9s0t1v2w3';

beforeEach(function () {
    seed(PlatformSeeder::class);
    Cx::actAsStaffWith([
        ...CatalogPermissions::sharedLists(),
        CatalogPermissions::CATEGORY_RANK,
        CatalogPermissions::PRODUCT_CREATE,
        CatalogPermissions::PRODUCT_UPDATE,
        CatalogPermissions::PRODUCT_ARCHIVE,
        CatalogPermissions::VARIANT_CORRECT_CODE,
        CatalogPermissions::PRODUCT_PUBLISH,
        CatalogPermissions::LISTING_CHOOSE,
        CatalogPermissions::LISTING_SELLING,
        CatalogPermissions::LISTING_UNAVAILABLE,
        CatalogPermissions::LISTING_LABELS,
    ]);
});

/**
 * A ready product chosen in the base store.
 *
 * @return array{string, string} the store's id, the product's
 */
function catalogGuardsChosen(): array
{
    $ready = Px::ready();
    $store = Fx::storeId('sa');
    app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $ready['product'], true));

    return [$store, $ready['product']];
}

function catalogGuardsNext(): int
{
    static $next = 0;

    return ++$next;
}

function catalogGuardsBrand(): string
{
    $n = catalogGuardsNext();

    return app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR'));
}

/** A brand that is not the default, so it may be deactivated and deleted. */
function catalogGuardsOtherBrand(): string
{
    catalogGuardsBrand();

    return catalogGuardsBrand();
}

function catalogGuardsCategory(?string $parentId = null): string
{
    $n = catalogGuardsNext();

    return app(AddCategoryHandler::class)->handle(new AddCategory("قسم {$n}", "Category {$n}", $parentId));
}

function catalogGuardsAttribute(): string
{
    $n = catalogGuardsNext();

    return app(AddAttributeHandler::class)->handle(new AddAttribute("خاصية {$n}", "Attribute {$n}", 'VARIANT'));
}

function catalogGuardsValue(string $attributeId): string
{
    $n = catalogGuardsNext();

    return app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($attributeId, "قيمة {$n}", "Value {$n}"));
}

function catalogGuardsSet(string $attributeId): string
{
    $n = catalogGuardsNext();

    return app(AddAttributeSetHandler::class)->handle(new AddAttributeSet("مجموعة {$n}", "Set {$n}", [$attributeId]));
}

function catalogGuardsLabel(): string
{
    $n = catalogGuardsNext();

    return app(AddLabelHandler::class)->handle(new AddLabel("شارة {$n}", "Label {$n}", 'blue'));
}

/**
 * @return array{blocks: list<array<string, mixed>>}
 */
function catalogGuardsTerms(string $text = 'Terms'): array
{
    return ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]];
}

function catalogGuardsWarranty(): string
{
    $n = catalogGuardsNext();

    return app(AddWarrantyHandler::class)->handle(new AddWarranty("ضمان {$n}", "Warranty {$n}", catalogGuardsTerms(), catalogGuardsTerms(), 12));
}

/** A draft on a brand of its own (no default brand exists in these tests), made first if not given. */
function catalogGuardsProduct(?string $brandId = null): string
{
    $brandId ??= catalogGuardsBrand();
    $n = catalogGuardsNext();

    return app(CreateProductHandler::class)->handle(new CreateProduct(Fx::storeId('sa'), "منتج {$n}", "Product {$n}", $brandId));
}

/** A draft with everything a ready product needs. */
function catalogGuardsWhole(): string
{
    $id = catalogGuardsProduct();
    $row = DB::table('catalog.products')->where('id', $id)->sole();
    $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Text']]]]];
    app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, (string) $row->name_ar, (string) $row->name_en, (string) $row->brand_id, descriptionAr: $text, descriptionEn: $text, categoryId: catalogGuardsCategory()));
    catalogGuardsVariant($id);
    app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [Cx::media()]));

    return $id;
}

function catalogGuardsVariant(string $productId): string
{
    return app(AddVariantHandler::class)->handle(new AddVariant($productId, (string) (1000 + catalogGuardsNext())));
}

function catalogGuardsPair(): string
{
    $n = catalogGuardsNext();

    return app(AddWordPairHandler::class)->handle(new AddWordPair("word{$n}", "كلمة{$n}"));
}

/**
 * The changes one audit entry recorded, keys sorted (jsonb keeps them shortest first).
 *
 * @return array<array-key, mixed>
 */
function catalogGuardsAuditOf(string $action, string $subjectId): array
{
    $changes = (array) json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->where('subject_id', $subjectId)->value('changes'), true);
    ksort($changes);

    return $changes;
}

/**
 * Every change to a shared list, each prepared in its own test: the list whose lock it takes, and
 * a function that sets up what it needs and answers the change itself.
 *
 * @return array<string, array{string, Closure}>
 */
function catalogGuardsChanges(): array
{
    return [
        'add a brand' => ['brands', fn () => fn () => catalogGuardsBrand()],
        'edit a brand' => ['products,brands', function () {
            $id = catalogGuardsBrand();

            return fn () => app(EditBrandHandler::class)->handle(new EditBrand($id, 'ماركة معدلة', 'Edited brand', 'DISTRIBUTOR', true, 0));
        }],
        'make a brand the default' => ['brands', function () {
            $id = catalogGuardsOtherBrand();

            return fn () => app(MakeBrandDefaultHandler::class)->handle(new MakeBrandDefault($id));
        }],
        'deactivate a brand' => ['products,brands', function () {
            $id = catalogGuardsOtherBrand();

            return fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($id));
        }],
        'activate a brand' => ['products,brands', function () {
            $id = catalogGuardsOtherBrand();
            app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($id));

            return fn () => app(ActivateBrandHandler::class)->handle(new ActivateBrand($id));
        }],
        'delete a brand' => ['brands', function () {
            $id = catalogGuardsOtherBrand();

            return fn () => app(DeleteBrandHandler::class)->handle(new DeleteBrand($id));
        }],
        'add a category' => ['categories', fn () => fn () => catalogGuardsCategory()],
        'edit a category' => ['products,categories', function () {
            $id = catalogGuardsCategory();

            return fn () => app(EditCategoryHandler::class)->handle(new EditCategory($id, 'قسم معدل', 'Edited category'));
        }],
        'move a category' => ['products,categories', function () {
            $parent = catalogGuardsCategory();
            $id = catalogGuardsCategory();

            return fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($id, $parent, 1));
        }],
        'deactivate a category' => ['products,categories', function () {
            $id = catalogGuardsCategory();

            return fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($id));
        }],
        'activate a category' => ['products,categories', function () {
            $id = catalogGuardsCategory();
            app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($id));

            return fn () => app(ActivateCategoryHandler::class)->handle(new ActivateCategory($id));
        }],
        'delete a category' => ['categories', function () {
            $id = catalogGuardsCategory();

            return fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory($id));
        }],
        'rank a category in a store' => ['categories', function () {
            $id = catalogGuardsCategory();

            return fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('eg'), [$id => 3]));
        }],
        'add an attribute' => ['attributes', fn () => fn () => catalogGuardsAttribute()],
        'edit an attribute' => ['attributes', function () {
            $id = catalogGuardsAttribute();

            return fn () => app(EditAttributeHandler::class)->handle(new EditAttribute($id, 'خاصية معدلة', 'Edited attribute', 'VARIANT'));
        }],
        'deactivate an attribute' => ['attributes', function () {
            $id = catalogGuardsAttribute();

            return fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($id));
        }],
        'activate an attribute' => ['attributes', function () {
            $id = catalogGuardsAttribute();
            app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($id));

            return fn () => app(ActivateAttributeHandler::class)->handle(new ActivateAttribute($id));
        }],
        'delete an attribute' => ['attributes', function () {
            $id = catalogGuardsAttribute();
            catalogGuardsValue($id);

            return fn () => app(DeleteAttributeHandler::class)->handle(new DeleteAttribute($id));
        }],
        'add a value' => ['attributes', function () {
            $id = catalogGuardsAttribute();

            return fn () => catalogGuardsValue($id);
        }],
        'edit a value' => ['attributes', function () {
            $id = catalogGuardsValue(catalogGuardsAttribute());

            return fn () => app(EditAttributeValueHandler::class)->handle(new EditAttributeValue($id, 'قيمة معدلة', 'Edited value'));
        }],
        'deactivate a value' => ['attributes', function () {
            $id = catalogGuardsValue(catalogGuardsAttribute());

            return fn () => app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($id));
        }],
        'activate a value' => ['attributes', function () {
            $id = catalogGuardsValue(catalogGuardsAttribute());
            app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($id));

            return fn () => app(ActivateAttributeValueHandler::class)->handle(new ActivateAttributeValue($id));
        }],
        'delete a value' => ['attributes', function () {
            $id = catalogGuardsValue(catalogGuardsAttribute());

            return fn () => app(DeleteAttributeValueHandler::class)->handle(new DeleteAttributeValue($id));
        }],
        'add a set' => ['attributes', function () {
            $attribute = catalogGuardsAttribute();

            return fn () => catalogGuardsSet($attribute);
        }],
        'edit a set' => ['attributes', function () {
            $attribute = catalogGuardsAttribute();
            $id = catalogGuardsSet($attribute);

            return fn () => app(EditAttributeSetHandler::class)->handle(new EditAttributeSet($id, 'مجموعة معدلة', 'Edited set', [$attribute]));
        }],
        'deactivate a set' => ['attributes', function () {
            $id = catalogGuardsSet(catalogGuardsAttribute());

            return fn () => app(DeactivateAttributeSetHandler::class)->handle(new DeactivateAttributeSet($id));
        }],
        'activate a set' => ['attributes', function () {
            $id = catalogGuardsSet(catalogGuardsAttribute());
            app(DeactivateAttributeSetHandler::class)->handle(new DeactivateAttributeSet($id));

            return fn () => app(ActivateAttributeSetHandler::class)->handle(new ActivateAttributeSet($id));
        }],
        'delete a set' => ['attributes', function () {
            $id = catalogGuardsSet(catalogGuardsAttribute());

            return fn () => app(DeleteAttributeSetHandler::class)->handle(new DeleteAttributeSet($id));
        }],
        'add a label' => ['labels', fn () => fn () => catalogGuardsLabel()],
        'edit a label' => ['labels', function () {
            $id = catalogGuardsLabel();

            return fn () => app(EditLabelHandler::class)->handle(new EditLabel($id, 'شارة معدلة', 'Edited label', 'green'));
        }],
        'deactivate a label' => ['labels', function () {
            $id = catalogGuardsLabel();

            return fn () => app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($id));
        }],
        'activate a label' => ['labels', function () {
            $id = catalogGuardsLabel();
            app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($id));

            return fn () => app(ActivateLabelHandler::class)->handle(new ActivateLabel($id));
        }],
        'delete a label' => ['labels', function () {
            $id = catalogGuardsLabel();

            return fn () => app(DeleteLabelHandler::class)->handle(new DeleteLabel($id));
        }],
        'add a warranty' => ['warranties', fn () => fn () => catalogGuardsWarranty()],
        'edit a warranty' => ['warranties', function () {
            $id = catalogGuardsWarranty();

            return fn () => app(EditWarrantyHandler::class)->handle(new EditWarranty($id, 'ضمان معدل', 'Edited warranty', catalogGuardsTerms(), catalogGuardsTerms(), 24));
        }],
        'deactivate a warranty' => ['warranties', function () {
            $id = catalogGuardsWarranty();

            return fn () => app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty($id));
        }],
        'activate a warranty' => ['warranties', function () {
            $id = catalogGuardsWarranty();
            app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty($id));

            return fn () => app(ActivateWarrantyHandler::class)->handle(new ActivateWarranty($id));
        }],
        'delete a warranty' => ['warranties', function () {
            $id = catalogGuardsWarranty();

            return fn () => app(DeleteWarrantyHandler::class)->handle(new DeleteWarranty($id));
        }],
        'create a product' => ['products', function () {
            $brand = catalogGuardsBrand();

            return fn () => catalogGuardsProduct($brand);
        }],
        'edit a product' => ['products', function () {
            $id = catalogGuardsProduct();
            $brand = catalogGuardsBrand();

            return fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, 'منتج معدل', 'Edited product', $brand));
        }],
        'delete a draft product' => ['products', function () {
            $id = catalogGuardsProduct();

            return fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($id));
        }],
        'add a variant' => ['products', function () {
            $id = catalogGuardsProduct();

            return fn () => catalogGuardsVariant($id);
        }],
        'update a variant' => ['products', function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());

            return fn () => app(UpdateVariantHandler::class)->handle(new UpdateVariant($id, '99', position: 2));
        }],
        'delete a draft variant' => ['products', function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());

            return fn () => app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($id));
        }],
        'correct a code' => ['products', function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());

            return fn () => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($id, '98'));
        }],
        'set a gallery' => ['products', function () {
            $id = catalogGuardsProduct();
            $photo = Cx::media();

            return fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [$photo]));
        }],
        'set a variant\'s photos' => ['products', function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());
            $photo = Cx::media();

            return fn () => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($id, [$photo]));
        }],
        'set search words' => ['products', function () {
            $id = catalogGuardsProduct();

            return fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($id, ['slide']));
        }],
        'set filter values' => ['products', function () {
            $id = catalogGuardsProduct();
            $attribute = app(AddAttributeHandler::class)->handle(new AddAttribute('خاصية تصفية', 'Filter', 'FILTERABLE'));
            $value = catalogGuardsValue($attribute);

            return fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($id, [$value]));
        }],
        'set relations' => ['products', function () {
            $id = catalogGuardsProduct();
            $ready = catalogGuardsProduct();
            DB::table('catalog.products')->where('id', $ready)->update(['stage' => 'READY', 'category_id' => catalogGuardsCategory()]);

            return fn () => app(SetRelationsHandler::class)->handle(new SetRelations($id, 'RELATED', [$ready]));
        }],
        'archive a product' => ['products', function () {
            $id = catalogGuardsProduct();

            return fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id));
        }],
        'choose in a store' => ['products', function () {
            $ready = Px::ready();

            return fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true));
        }],
        'set selling terms' => ['products', function () {
            [$store, $product] = catalogGuardsChosen();

            return fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $product, retailMinimum: 2));
        }],
        'mark not available now' => ['products', function () {
            [$store, $product] = catalogGuardsChosen();

            return fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($store, $product));
        }],
        'clear not available now' => ['products', function () {
            [$store, $product] = catalogGuardsChosen();
            app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($store, $product));

            return fn () => app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow($store, $product));
        }],
        'attach labels' => ['products', function () {
            [$store, $product] = catalogGuardsChosen();
            $label = catalogGuardsLabel();

            return fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($store, $product, [$label]));
        }],
        'restore a product' => ['products', function () {
            $id = catalogGuardsWhole();
            app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id));

            return fn () => app(RestoreProductHandler::class)->handle(new RestoreProduct($id));
        }],
        'make a product ready' => ['products', function () {
            $id = catalogGuardsWhole();

            return fn () => app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
        }],
        'archive a variant' => ['products', function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());

            return fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($id));
        }],
        'restore a variant' => ['products', function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());
            app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($id));

            return fn () => app(RestoreVariantHandler::class)->handle(new RestoreVariant($id));
        }],
        'add a word pair' => ['word_pairs', fn () => fn () => catalogGuardsPair()],
        'delete a word pair' => ['word_pairs', function () {
            $id = catalogGuardsPair();

            return fn () => app(DeleteWordPairHandler::class)->handle(new DeleteWordPair($id));
        }],
    ];
}

describe('the lock', function () {
    it('is the first thing a change does inside its own transaction, and its list\'s', function (string $list, Closure $prepare) {
        $change = $prepare();
        $queries = Cx::recordQueries();

        $change();

        // Level 2: the handler's own transaction under RefreshDatabase's. Authorizing comes before
        // it, at level 1; everything the change reads and writes comes after the lock.
        $inside = array_values(array_filter((array) $queries, static fn (array $query): bool => $query['level'] >= 2));

        // A change that changes products as well as its list takes the products' lock first (step 4).
        $keys = array_map(static fn (string $key): string => "catalog:{$key}", explode(',', $list));
        $taken = array_values(array_unique(array_map(
            static fn (array $query): string => (string) ($query['bindings'][0] ?? ''),
            array_filter($inside, static fn (array $query): bool => str_contains($query['sql'], 'pg_advisory_xact_lock')),
        )));

        expect($inside)->not->toBeEmpty()
            ->and($inside[0]['sql'])->toContain('pg_advisory_xact_lock')
            ->and($inside[0]['bindings'])->toBe([$keys[0]])
            ->and(array_map(static fn (array $query): mixed => $query['bindings'][0] ?? null, array_slice($inside, 0, count($keys))))->toBe($keys)
            ->and($taken)->toBe($keys);
    })->with(catalogGuardsChanges());
});

describe('an id that is not in its list', function () {
    it('is answered as not found', function (string $error, Closure $change) {
        expect($change)->toThrow($error);
    })->with([
        'edit a brand' => [BrandNotFound::class, fn () => app(EditBrandHandler::class)->handle(new EditBrand(CATALOG_GUARDS_UNKNOWN, 'ماركة', 'Brand', 'DISTRIBUTOR', true, 0))],
        'make a brand the default' => [BrandNotFound::class, fn () => app(MakeBrandDefaultHandler::class)->handle(new MakeBrandDefault(CATALOG_GUARDS_UNKNOWN))],
        'deactivate a brand' => [BrandNotFound::class, fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand(CATALOG_GUARDS_UNKNOWN))],
        'activate a brand' => [BrandNotFound::class, fn () => app(ActivateBrandHandler::class)->handle(new ActivateBrand(CATALOG_GUARDS_UNKNOWN))],
        'delete a brand' => [BrandNotFound::class, fn () => app(DeleteBrandHandler::class)->handle(new DeleteBrand(CATALOG_GUARDS_UNKNOWN))],
        'edit a category' => [CategoryNotFound::class, fn () => app(EditCategoryHandler::class)->handle(new EditCategory(CATALOG_GUARDS_UNKNOWN, 'قسم', 'Category'))],
        'move a category' => [CategoryNotFound::class, fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory(CATALOG_GUARDS_UNKNOWN, null))],
        'move under an unknown parent' => [CategoryNotFound::class, fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory(catalogGuardsCategory(), CATALOG_GUARDS_UNKNOWN))],
        'deactivate a category' => [CategoryNotFound::class, fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory(CATALOG_GUARDS_UNKNOWN))],
        'activate a category' => [CategoryNotFound::class, fn () => app(ActivateCategoryHandler::class)->handle(new ActivateCategory(CATALOG_GUARDS_UNKNOWN))],
        'delete a category' => [CategoryNotFound::class, fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory(CATALOG_GUARDS_UNKNOWN))],
        'add under an unknown parent' => [CategoryNotFound::class, fn () => catalogGuardsCategory(CATALOG_GUARDS_UNKNOWN)],
        'rank an unknown category' => [CategoryNotFound::class, fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('eg'), [CATALOG_GUARDS_UNKNOWN => 1]))],
        'edit an attribute' => [ListItemNotFound::class, fn () => app(EditAttributeHandler::class)->handle(new EditAttribute(CATALOG_GUARDS_UNKNOWN, 'خاصية', 'Attribute', 'VARIANT'))],
        'deactivate an attribute' => [ListItemNotFound::class, fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute(CATALOG_GUARDS_UNKNOWN))],
        'activate an attribute' => [ListItemNotFound::class, fn () => app(ActivateAttributeHandler::class)->handle(new ActivateAttribute(CATALOG_GUARDS_UNKNOWN))],
        'delete an attribute' => [ListItemNotFound::class, fn () => app(DeleteAttributeHandler::class)->handle(new DeleteAttribute(CATALOG_GUARDS_UNKNOWN))],
        'add a value to an unknown attribute' => [ListItemNotFound::class, fn () => catalogGuardsValue(CATALOG_GUARDS_UNKNOWN)],
        'edit a value' => [ListItemNotFound::class, fn () => app(EditAttributeValueHandler::class)->handle(new EditAttributeValue(CATALOG_GUARDS_UNKNOWN, 'قيمة', 'Value'))],
        'deactivate a value' => [ListItemNotFound::class, fn () => app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue(CATALOG_GUARDS_UNKNOWN))],
        'activate a value' => [ListItemNotFound::class, fn () => app(ActivateAttributeValueHandler::class)->handle(new ActivateAttributeValue(CATALOG_GUARDS_UNKNOWN))],
        'delete a value' => [ListItemNotFound::class, fn () => app(DeleteAttributeValueHandler::class)->handle(new DeleteAttributeValue(CATALOG_GUARDS_UNKNOWN))],
        'add a set of an unknown attribute' => [ListItemNotFound::class, fn () => catalogGuardsSet(CATALOG_GUARDS_UNKNOWN)],
        'edit a set' => [ListItemNotFound::class, fn () => app(EditAttributeSetHandler::class)->handle(new EditAttributeSet(CATALOG_GUARDS_UNKNOWN, 'مجموعة', 'Set', [catalogGuardsAttribute()]))],
        'deactivate a set' => [ListItemNotFound::class, fn () => app(DeactivateAttributeSetHandler::class)->handle(new DeactivateAttributeSet(CATALOG_GUARDS_UNKNOWN))],
        'activate a set' => [ListItemNotFound::class, fn () => app(ActivateAttributeSetHandler::class)->handle(new ActivateAttributeSet(CATALOG_GUARDS_UNKNOWN))],
        'delete a set' => [ListItemNotFound::class, fn () => app(DeleteAttributeSetHandler::class)->handle(new DeleteAttributeSet(CATALOG_GUARDS_UNKNOWN))],
        'edit a label' => [ListItemNotFound::class, fn () => app(EditLabelHandler::class)->handle(new EditLabel(CATALOG_GUARDS_UNKNOWN, 'شارة', 'Label', 'blue'))],
        'deactivate a label' => [ListItemNotFound::class, fn () => app(DeactivateLabelHandler::class)->handle(new DeactivateLabel(CATALOG_GUARDS_UNKNOWN))],
        'activate a label' => [ListItemNotFound::class, fn () => app(ActivateLabelHandler::class)->handle(new ActivateLabel(CATALOG_GUARDS_UNKNOWN))],
        'delete a label' => [ListItemNotFound::class, fn () => app(DeleteLabelHandler::class)->handle(new DeleteLabel(CATALOG_GUARDS_UNKNOWN))],
        'edit a warranty' => [ListItemNotFound::class, fn () => app(EditWarrantyHandler::class)->handle(new EditWarranty(CATALOG_GUARDS_UNKNOWN, 'ضمان', 'Warranty', catalogGuardsTerms(), catalogGuardsTerms(), 12))],
        'deactivate a warranty' => [ListItemNotFound::class, fn () => app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty(CATALOG_GUARDS_UNKNOWN))],
        'activate a warranty' => [ListItemNotFound::class, fn () => app(ActivateWarrantyHandler::class)->handle(new ActivateWarranty(CATALOG_GUARDS_UNKNOWN))],
        'delete a warranty' => [ListItemNotFound::class, fn () => app(DeleteWarrantyHandler::class)->handle(new DeleteWarranty(CATALOG_GUARDS_UNKNOWN))],
        'delete a word pair' => [ListItemNotFound::class, fn () => app(DeleteWordPairHandler::class)->handle(new DeleteWordPair(CATALOG_GUARDS_UNKNOWN))],
        'edit a product' => [ProductNotFound::class, fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails(CATALOG_GUARDS_UNKNOWN, 'منتج', 'Product', catalogGuardsBrand()))],
        'delete a draft product' => [ProductNotFound::class, fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct(CATALOG_GUARDS_UNKNOWN))],
        'add a variant to an unknown product' => [ProductNotFound::class, fn () => app(AddVariantHandler::class)->handle(new AddVariant(CATALOG_GUARDS_UNKNOWN, '1001'))],
        'update a variant' => [VariantNotFound::class, fn () => app(UpdateVariantHandler::class)->handle(new UpdateVariant(CATALOG_GUARDS_UNKNOWN, '1001'))],
        'delete a draft variant' => [VariantNotFound::class, fn () => app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant(CATALOG_GUARDS_UNKNOWN))],
        'correct a code' => [VariantNotFound::class, fn () => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode(CATALOG_GUARDS_UNKNOWN, '1001'))],
        'set a gallery' => [ProductNotFound::class, fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery(CATALOG_GUARDS_UNKNOWN, []))],
        'archive a product' => [ProductNotFound::class, fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct(CATALOG_GUARDS_UNKNOWN))],
        'restore a product' => [ProductNotFound::class, fn () => app(RestoreProductHandler::class)->handle(new RestoreProduct(CATALOG_GUARDS_UNKNOWN))],
        'make a product ready' => [ProductNotFound::class, fn () => app(MarkProductReadyHandler::class)->handle(new MarkProductReady(CATALOG_GUARDS_UNKNOWN))],
        'archive a variant' => [VariantNotFound::class, fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant(CATALOG_GUARDS_UNKNOWN))],
        'restore a variant' => [VariantNotFound::class, fn () => app(RestoreVariantHandler::class)->handle(new RestoreVariant(CATALOG_GUARDS_UNKNOWN))],
        'set a variant\'s photos' => [VariantNotFound::class, fn () => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos(CATALOG_GUARDS_UNKNOWN, []))],
        'set search words' => [ProductNotFound::class, fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords(CATALOG_GUARDS_UNKNOWN, []))],
        'set filter values' => [ProductNotFound::class, fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues(CATALOG_GUARDS_UNKNOWN, []))],
        'set relations' => [ProductNotFound::class, fn () => app(SetRelationsHandler::class)->handle(new SetRelations(CATALOG_GUARDS_UNKNOWN, 'RELATED', []))],
        'choose in a store' => [ProductNotFound::class, fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), CATALOG_GUARDS_UNKNOWN, true))],
        'set selling terms' => [ProductNotFound::class, fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), CATALOG_GUARDS_UNKNOWN))],
        'mark not available now' => [ProductNotFound::class, fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), CATALOG_GUARDS_UNKNOWN))],
        'clear not available now' => [ProductNotFound::class, fn () => app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow(Fx::storeId('sa'), CATALOG_GUARDS_UNKNOWN))],
        'attach labels' => [ProductNotFound::class, fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels(Fx::storeId('sa'), CATALOG_GUARDS_UNKNOWN, []))],
    ]);
});

describe('a change that changes nothing', function () {
    it('writes nothing to the lists and records nothing', function (Closure $prepare) {
        $change = $prepare();
        $audits = DB::table('platform.audit_entries')->count();
        $queries = Cx::recordQueries();

        $change();

        $writes = array_filter((array) $queries, static fn (array $query): bool => preg_match('/^\s*(insert|update|delete)\b.*\bcatalog\b/is', $query['sql']) === 1);

        expect(DB::table('platform.audit_entries')->count())->toBe($audits)
            ->and(array_values($writes))->toBe([]);
    })->with([
        'make the default the default' => [function () {
            $id = catalogGuardsBrand();

            return fn () => app(MakeBrandDefaultHandler::class)->handle(new MakeBrandDefault($id));
        }],
        'deactivate a brand again' => [function () {
            $id = catalogGuardsOtherBrand();
            app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($id));

            return fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($id));
        }],
        'activate an active brand' => [function () {
            $id = catalogGuardsOtherBrand();

            return fn () => app(ActivateBrandHandler::class)->handle(new ActivateBrand($id));
        }],
        'move a category under the parent it has' => [function () {
            $parent = catalogGuardsCategory();
            $id = catalogGuardsCategory($parent);

            return fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($id, $parent, 5));
        }],
        'rank a category where it is' => [function () {
            $id = catalogGuardsCategory();

            return fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('eg'), [$id => 0]));
        }],
        'deactivate an attribute again' => [function () {
            $id = catalogGuardsAttribute();
            app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($id));

            return fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($id));
        }],
        'activate an active attribute' => [function () {
            $id = catalogGuardsAttribute();

            return fn () => app(ActivateAttributeHandler::class)->handle(new ActivateAttribute($id));
        }],
        'edit a value to what it is' => [function () {
            $n = catalogGuardsNext();
            $id = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue(catalogGuardsAttribute(), "قيمة {$n}", "Value {$n}"));

            return fn () => app(EditAttributeValueHandler::class)->handle(new EditAttributeValue($id, "قيمة {$n}", "Value {$n}"));
        }],
        'deactivate a value again' => [function () {
            $id = catalogGuardsValue(catalogGuardsAttribute());
            app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($id));

            return fn () => app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($id));
        }],
        'activate an active value' => [function () {
            $id = catalogGuardsValue(catalogGuardsAttribute());

            return fn () => app(ActivateAttributeValueHandler::class)->handle(new ActivateAttributeValue($id));
        }],
        'edit a set to what it is' => [function () {
            $attribute = catalogGuardsAttribute();
            $n = catalogGuardsNext();
            $id = app(AddAttributeSetHandler::class)->handle(new AddAttributeSet("مجموعة {$n}", "Set {$n}", [$attribute]));

            return fn () => app(EditAttributeSetHandler::class)->handle(new EditAttributeSet($id, "مجموعة {$n}", "Set {$n}", [$attribute]));
        }],
        'deactivate a set again' => [function () {
            $id = catalogGuardsSet(catalogGuardsAttribute());
            app(DeactivateAttributeSetHandler::class)->handle(new DeactivateAttributeSet($id));

            return fn () => app(DeactivateAttributeSetHandler::class)->handle(new DeactivateAttributeSet($id));
        }],
        'activate an active set' => [function () {
            $id = catalogGuardsSet(catalogGuardsAttribute());

            return fn () => app(ActivateAttributeSetHandler::class)->handle(new ActivateAttributeSet($id));
        }],
        'deactivate a label again' => [function () {
            $id = catalogGuardsLabel();
            app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($id));

            return fn () => app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($id));
        }],
        'activate an active label' => [function () {
            $id = catalogGuardsLabel();

            return fn () => app(ActivateLabelHandler::class)->handle(new ActivateLabel($id));
        }],
        'deactivate a warranty again' => [function () {
            $id = catalogGuardsWarranty();
            app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty($id));

            return fn () => app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty($id));
        }],
        'activate an active warranty' => [function () {
            $id = catalogGuardsWarranty();

            return fn () => app(ActivateWarrantyHandler::class)->handle(new ActivateWarranty($id));
        }],
        'edit a product to what it is' => [function () {
            $id = catalogGuardsProduct();
            $row = DB::table('catalog.products')->where('id', $id)->sole();

            return fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, (string) $row->name_ar, (string) $row->name_en, (string) $row->brand_id));
        }],
        'update a variant to what it is' => [function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());
            $code = (string) DB::table('catalog.variants')->where('id', $id)->value('code');

            return fn () => app(UpdateVariantHandler::class)->handle(new UpdateVariant($id, $code));
        }],
        'set a gallery to what it is' => [function () {
            $id = catalogGuardsProduct();

            return fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, []));
        }],
        'set a variant\'s photos to what they are' => [function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());

            return fn () => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($id, []));
        }],
        'set search words to what they are' => [function () {
            $id = catalogGuardsProduct();
            app(SetSearchWordsHandler::class)->handle(new SetSearchWords($id, ['slide', 'rail']));

            return fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($id, ['slide', 'rail', 'Slide']));
        }],
        'set filter values to what they are' => [function () {
            $id = catalogGuardsProduct();

            return fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($id, []));
        }],
        'set relations to what they are' => [function () {
            $id = catalogGuardsProduct();

            return fn () => app(SetRelationsHandler::class)->handle(new SetRelations($id, 'GOES_WITH', []));
        }],
        'archive an archived product' => [function () {
            $id = catalogGuardsProduct();
            app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id));

            return fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id));
        }],
        'restore a ready product' => [function () {
            $id = catalogGuardsProduct();
            DB::table('catalog.products')->where('id', $id)->update(['stage' => 'READY', 'category_id' => catalogGuardsCategory()]);

            return fn () => app(RestoreProductHandler::class)->handle(new RestoreProduct($id));
        }],
        'make a ready product ready' => [function () {
            $id = catalogGuardsProduct();
            DB::table('catalog.products')->where('id', $id)->update(['stage' => 'READY', 'category_id' => catalogGuardsCategory()]);

            return fn () => app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
        }],
        'archive an archived variant' => [function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());
            app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($id));

            return fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($id));
        }],
        'restore a variant not archived' => [function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());

            return fn () => app(RestoreVariantHandler::class)->handle(new RestoreVariant($id));
        }],
        'correct a code to itself' => [function () {
            $id = catalogGuardsVariant(catalogGuardsProduct());
            $code = (string) DB::table('catalog.variants')->where('id', $id)->value('code');

            return fn () => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($id, " {$code} "));
        }],
        'choose what is chosen' => [function () {
            [$store, $product] = catalogGuardsChosen();

            return fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $product, true));
        }],
        'set the same selling terms' => [function () {
            [$store, $product] = catalogGuardsChosen();

            return fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $product));
        }],
        'mark what is marked' => [function () {
            [$store, $product] = catalogGuardsChosen();
            app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($store, $product));

            return fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($store, $product));
        }],
        'clear what is clear' => [function () {
            [$store, $product] = catalogGuardsChosen();

            return fn () => app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow($store, $product));
        }],
        'attach the same labels' => [function () {
            [$store, $product] = catalogGuardsChosen();
            $label = catalogGuardsLabel();
            app(AttachLabelsHandler::class)->handle(new AttachLabels($store, $product, [$label]));

            return fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($store, $product, [$label]));
        }],
    ]);
});

describe('what the audit log keeps', function () {
    it('reads from what was to what is: deactivating, moving the default, ranking, deleting', function () {
        $first = catalogGuardsBrand();
        $second = catalogGuardsBrand();
        app(MakeBrandDefaultHandler::class)->handle(new MakeBrandDefault($second));
        $label = catalogGuardsLabel();
        app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($label));
        $category = catalogGuardsCategory();
        $eg = Fx::storeId('eg');
        app(RankCategoriesHandler::class)->handle(new RankCategories($eg, [$category => 3]));
        $warranty = catalogGuardsWarranty();
        $name = DB::table('catalog.warranties')->where('id', $warranty)->value('name_en');
        app(DeleteWarrantyHandler::class)->handle(new DeleteWarranty($warranty));

        expect(catalogGuardsAuditOf('catalog.brand.default_moved', $first))->toBe(['is_default' => [true, false]])
            ->and(catalogGuardsAuditOf('catalog.brand.made_default', $second))->toBe(['is_default' => [false, true]])
            ->and(catalogGuardsAuditOf('catalog.label.deactivated', $label))->toBe(['is_active' => [true, false]])
            ->and(catalogGuardsAuditOf('catalog.category.ranked', $category))->toBe(['rank' => [0, 3]])
            ->and(DB::table('platform.audit_entries')->where('action', 'catalog.category.ranked')->value('store_id'))->toBe($eg)
            ->and(catalogGuardsAuditOf('catalog.warranty.deleted', $warranty))->toMatchArray(['name_en' => [$name, null], 'period_months' => [12, null]]);
    });
});

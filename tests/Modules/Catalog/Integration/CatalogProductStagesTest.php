<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCode;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCodeHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariant;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariantHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
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
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotos;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotosHandler;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariant;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariantHandler;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\Exception\ProductNotReady;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Events\ProductArchived as ProductArchivedEvent;
use Modules\Catalog\Public\Events\ProductChanged;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\ProductRestored;
use Modules\Catalog\Public\Events\VariantAdded;
use Modules\Catalog\Public\Events\VariantArchived;
use Modules\Catalog\Public\Events\VariantCodeCorrected;
use Modules\Catalog\Public\Events\VariantRestored;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| A product's stage (catalog.md §1.1, §4.1, §6.1, §7): made ready only when whole, each missing rule
| named; kept whole while ready; archived and restored, an archived product changed by nothing but
| restoring; a variant archived and restored on its own. Each change sends its event, ids only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Cx::actAsStaffWith([
        CatalogPermissions::PRODUCT_UPDATE,
        CatalogPermissions::PRODUCT_PUBLISH,
        CatalogPermissions::PRODUCT_ARCHIVE,
        CatalogPermissions::VARIANT_CORRECT_CODE,
    ]);
});

/**
 * @return array{blocks: list<array<string, mixed>>}
 */
function catalogStagesText(string $text): array
{
    return ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]];
}

/**
 * A draft with everything a ready product needs: both names, both descriptions, a lowest active
 * category, a 60 cm variant of a set of widths, a photo whose sizes are ready.
 *
 * @return array{string, string, string, string, string} the product, its variant, its photo, the
 *                                                       width attribute, an 80 cm value for another
 */
function catalogStagesWhole(): array
{
    $width = Px::attribute('Width');
    $sixty = Px::value($width, '60 cm');
    $eighty = Px::value($width, '80 cm');
    $id = Px::product('Drawer');
    $category = Px::category();
    $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No such product.');
    app(EditProductDetailsHandler::class)->handle(new EditProductDetails(
        $id, $product->name()->ar, $product->name()->en, $product->brandId(),
        descriptionAr: catalogStagesText('درج'), descriptionEn: catalogStagesText('Drawer'), categoryId: $category,
        attributeSetId: Px::set([$width]),
    ));
    $variant = Px::variant($id, '1304', [$width => $sixty]);
    $photo = Cx::media();
    app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [$photo]));

    return [$id, $variant, $photo, $width, $eighty];
}

/**
 * @param  array<string, mixed>  $changes
 */
function catalogStagesEdit(string $productId, array $changes): void
{
    $product = app(ProductRepository::class)->find($productId) ?? throw new LogicException('No such product.');

    app(EditProductDetailsHandler::class)->handle(new EditProductDetails(...[
        'productId' => $productId,
        'nameAr' => $product->name()->ar,
        'nameEn' => $product->name()->en,
        'brandId' => $product->brandId(),
        'descriptionAr' => $product->descriptionAr()?->toArray(),
        'descriptionEn' => $product->descriptionEn()?->toArray(),
        'categoryId' => $product->categoryId(),
        'attributeSetId' => $product->attributeSetId(),
        ...$changes,
    ]));
}

function catalogStagesStage(string $productId): string
{
    return (string) DB::table('catalog.products')->where('id', $productId)->value('stage');
}

describe('making a product ready', function () {
    it('names every rule a draft does not meet yet', function () {
        $id = Px::product(null);
        $refused = null;

        try {
            app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
        } catch (ProductNotReady $error) {
            $refused = $error;
        }

        expect($refused?->missing)->toBe(['name_en', 'description_ar', 'description_en', 'category', 'variants', 'photos'])
            ->and(catalogStagesStage($id))->toBe('DRAFT');
    });

    it('makes a whole draft ready, audited, its event sent', function () {
        Event::fake([ProductMadeReady::class]);
        [$id] = catalogStagesWhole();

        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));

        expect(catalogStagesStage($id))->toBe('READY')
            ->and(Fx::audits('catalog.product.made_ready', $id))->toBe(1);
        Event::assertDispatchedTimes(ProductMadeReady::class, 1);
        Event::assertDispatched(ProductMadeReady::class, fn (ProductMadeReady $event): bool => $event->productId === $id);
    });

    it('counts only a photo whose sizes are ready, a variant not archived, an active lowest category', function (Closure $spoil, string $missing) {
        [$id, $variant, $photo] = catalogStagesWhole();
        $spoil($id, $variant, $photo);

        expect(fn () => app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id)))
            ->toThrow(ProductNotReady::class, $missing);
    })->with([
        'a photo still being sized' => [fn (string $id, string $variant, string $photo) => DB::table('platform.media')->where('id', $photo)->update(['variants_status' => 'PENDING']), 'photos'],
        'its only variant archived' => [fn (string $id, string $variant) => DB::table('catalog.variants')->where('id', $variant)->update(['is_archived' => true]), 'variants'],
        'a category deactivated since' => [fn (string $id) => Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory((string) DB::table('catalog.products')->where('id', $id)->value('category_id')))), 'category'],
    ]);

    it('needs catalog.product.publish, and never reaches an archived product', function () {
        [$id] = catalogStagesWhole();
        DB::table('catalog.products')->where('id', $id)->update(['stage' => 'ARCHIVED']);

        expect(fn () => app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id)))->toThrow(ProductArchived::class);

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE]);

        expect(fn () => app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id)))->toThrow(Unauthorized::class);
    });
});

describe('a ready product stays whole', function () {
    it('refuses an edit that would take a rule away, naming it', function () {
        [$id, $variant, , $width, $eighty] = catalogStagesWhole();
        $second = Px::variant($id, '1305', [$width => $eighty]);
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));

        expect(fn () => catalogStagesEdit($id, ['descriptionAr' => null]))->toThrow(ProductNotReady::class, 'description_ar')
            ->and(fn () => catalogStagesEdit($id, ['nameEn' => null]))->toThrow(ProductNotReady::class, 'name_en')
            ->and(fn () => catalogStagesEdit($id, ['categoryId' => null]))->toThrow(ProductNotReady::class, 'category')
            ->and(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [])))->toThrow(ProductNotReady::class, 'photos');

        // One of two variants may go; the last may not.
        app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($second));

        expect(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($variant)))->toThrow(ProductNotReady::class, 'variants')
            ->and(DB::table('catalog.variants')->where('id', $variant)->value('is_archived'))->toBeFalse();
    });
});

describe('archiving and restoring', function () {
    it('archives a ready product or an abandoned draft, and restores a whole one, ready', function () {
        Event::fake([ProductArchivedEvent::class, ProductRestored::class]);
        [$ready] = catalogStagesWhole();
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($ready));
        $draft = Px::product();

        app(ArchiveProductHandler::class)->handle(new ArchiveProduct($ready));
        app(ArchiveProductHandler::class)->handle(new ArchiveProduct($draft));
        app(ArchiveProductHandler::class)->handle(new ArchiveProduct($ready));

        expect(catalogStagesStage($ready))->toBe('ARCHIVED')
            ->and(catalogStagesStage($draft))->toBe('ARCHIVED')
            ->and((array) json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.product.archived')->where('subject_id', $draft)->value('changes'), true))->toBe(['stage' => ['DRAFT', 'ARCHIVED']]);
        Event::assertDispatchedTimes(ProductArchivedEvent::class, 2);

        app(RestoreProductHandler::class)->handle(new RestoreProduct($ready));

        expect(catalogStagesStage($ready))->toBe('READY')
            ->and(Fx::audits('catalog.product.restored', $ready))->toBe(1)
            // A draft abandoned comes back only once it is whole.
            ->and(fn () => app(RestoreProductHandler::class)->handle(new RestoreProduct($draft)))->toThrow(ProductNotReady::class)
            ->and(catalogStagesStage($draft))->toBe('ARCHIVED');
        Event::assertDispatchedTimes(ProductRestored::class, 1);
    });

    it('restores only an archived product: a draft is made ready instead', function () {
        $draft = Px::product();

        expect(fn () => app(RestoreProductHandler::class)->handle(new RestoreProduct($draft)))->toThrow(InvalidStageChange::class);
    });

    it('refuses every change to an archived product but restoring it', function (Closure $change) {
        [$id, $variant] = catalogStagesWhole();
        app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id));

        expect(fn () => $change($id, $variant))->toThrow(ProductArchived::class);
    })->with([
        'its details' => [fn (string $id) => catalogStagesEdit($id, ['nameAr' => 'درج آخر'])],
        'a new variant' => [fn (string $id) => app(AddVariantHandler::class)->handle(new AddVariant($id, '1306'))],
        'a variant' => [fn (string $id, string $variant) => app(UpdateVariantHandler::class)->handle(new UpdateVariant($variant, '1304', position: 4))],
        'a code' => [fn (string $id, string $variant) => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($variant, '1307'))],
        'a variant archived' => [fn (string $id, string $variant) => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($variant))],
        'its gallery' => [fn (string $id) => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, []))],
        'a variant\'s photos' => [fn (string $id, string $variant) => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($variant, [Cx::media()]))],
        'its search words' => [fn (string $id) => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($id, ['slide']))],
        'its filter values' => [fn (string $id) => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($id, []))],
        'its relations' => [fn (string $id) => app(SetRelationsHandler::class)->handle(new SetRelations($id, 'RELATED', []))],
        'a variant restored' => [fn (string $id, string $variant) => app(RestoreVariantHandler::class)->handle(new RestoreVariant($variant))],
        'a variant deleted' => [fn (string $id, string $variant) => app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($variant))],
        'made ready' => [fn (string $id) => app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id))],
        'deleted' => [fn (string $id) => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($id))],
    ]);

    it('archives and restores a variant on its own, its events sent', function () {
        Event::fake([VariantArchived::class, VariantRestored::class]);
        [$id, , , $width, $eighty] = catalogStagesWhole();
        $second = Px::variant($id, '1305', [$width => $eighty]);

        app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($second));
        app(RestoreVariantHandler::class)->handle(new RestoreVariant($second));
        app(RestoreVariantHandler::class)->handle(new RestoreVariant($second));

        expect(DB::table('catalog.variants')->where('id', $second)->value('is_archived'))->toBeFalse()
            ->and(Fx::audits('catalog.variant.archived', $second))->toBe(1)
            ->and(Fx::audits('catalog.variant.restored', $second))->toBe(1);
        Event::assertDispatched(VariantArchived::class, fn (VariantArchived $event): bool => $event->variantId === $second && $event->productId === $id);
        Event::assertDispatchedTimes(VariantRestored::class, 1);
    });
});

describe('the events of everyday changes', function () {
    it('sends a variant added, a code corrected on every variant holding it, and a product changed', function () {
        Event::fake([VariantAdded::class, VariantCodeCorrected::class, ProductChanged::class]);
        $width = Px::attribute();
        $id = Px::product();
        catalogStagesEdit($id, ['attributeSetId' => Px::set([$width])]);
        Event::assertDispatchedTimes(ProductChanged::class, 1);

        $sixty = Px::variant($id, '1340', [$width => Px::value($width, '60 cm')]);
        $eighty = Px::variant($id, '1340', [$width => Px::value($width, '80 cm')]);
        app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($sixty, '1304'));
        app(SetSearchWordsHandler::class)->handle(new SetSearchWords($id, ['drawer']));

        Event::assertDispatchedTimes(VariantAdded::class, 2);
        Event::assertDispatched(VariantCodeCorrected::class, fn (VariantCodeCorrected $event): bool => $event->variantId === $sixty);
        Event::assertDispatched(VariantCodeCorrected::class, fn (VariantCodeCorrected $event): bool => $event->variantId === $eighty);
        Event::assertDispatchedTimes(ProductChanged::class, 2);
    });
});

describe('a ready product\'s gallery', function () {
    it('counts a photo still being sized as no photo', function () {
        [$id] = catalogStagesWhole();
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
        $pending = Cx::media();
        DB::table('platform.media')->where('id', $pending)->update(['variants_status' => 'PENDING']);

        expect(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [$pending])))->toThrow(ProductNotReady::class, 'photos');
    });
});

describe('what archiving a variant writes', function () {
    it('writes the variant\'s own row alone, never its values or details again', function () {
        [$id, , , $width, $eighty] = catalogStagesWhole();
        $second = Px::variant($id, '1305', [$width => $eighty]);
        $queries = Cx::recordQueries();

        app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($second));
        app(RestoreVariantHandler::class)->handle(new RestoreVariant($second));

        // Writing them again would take key locks on attribute and value rows it never locked.
        $written = array_filter((array) $queries, fn (array $query): bool => preg_match('/^(insert into|delete from) "catalog"\."variant_(values|details)"/i', $query['sql']) === 1);

        expect($written)->toBe([])
            ->and(DB::table('catalog.variant_values')->where('variant_id', $second)->count())->toBe(1);
    });
});

describe('a product changed', function () {
    it('is sent once for every change of its shared data', function (Closure $change) {
        [$id, $variant, , $width, $eighty] = catalogStagesWhole();
        Event::fake([ProductChanged::class]);

        $change($id, $variant, $width, $eighty);

        Event::assertDispatchedTimes(ProductChanged::class, 1);
        Event::assertDispatched(ProductChanged::class, fn (ProductChanged $event): bool => $event->productId === $id);
    })->with([
        'a variant edited' => [fn (string $id, string $variant, string $width, string $eighty) => app(UpdateVariantHandler::class)->handle(new UpdateVariant($variant, '1304', [$width => $eighty]))],
        'a draft\'s variant deleted' => [fn (string $id, string $variant) => app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($variant))],
        'its gallery' => [fn (string $id) => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [Cx::media()]))],
        'a variant\'s photos' => [fn (string $id, string $variant) => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($variant, [Cx::media()]))],
        'its filter values' => [function (string $id): void {
            $finish = Px::attribute('Finish', 'FILTERABLE');
            app(SetFilterValuesHandler::class)->handle(new SetFilterValues($id, [Px::value($finish, 'Oak')]));
        }],
        'its relations' => [function (string $id): void {
            $other = Px::product('Hinge');
            DB::table('catalog.products')->where('id', $other)->update(['stage' => 'READY', 'category_id' => Px::category()]);
            app(SetRelationsHandler::class)->handle(new SetRelations($id, 'RELATED', [$other]));
        }],
    ]);
});

describe('each change\'s own job', function () {
    it('needs its own job, and no other will do', function (string $permission, Closure $prepare, Closure $change) {
        [$id, , , $width, $eighty] = catalogStagesWhole();
        $second = Px::variant($id, '1305', [$width => $eighty]);
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
        $prepare($id, $second);
        $all = [
            CatalogPermissions::PRODUCT_CREATE,
            CatalogPermissions::PRODUCT_UPDATE,
            CatalogPermissions::PRODUCT_PUBLISH,
            CatalogPermissions::PRODUCT_ARCHIVE,
            CatalogPermissions::VARIANT_CORRECT_CODE,
        ];

        Cx::actAsStaffWith(array_values(array_diff($all, [$permission])));

        expect(fn () => $change($id, $second))->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([$permission]);
        $change($id, $second);
    })->with([
        'archive a product' => [CatalogPermissions::PRODUCT_ARCHIVE, fn () => null, fn (string $id) => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id))],
        'restore a product' => [
            CatalogPermissions::PRODUCT_ARCHIVE,
            fn (string $id) => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id)),
            fn (string $id) => app(RestoreProductHandler::class)->handle(new RestoreProduct($id)),
        ],
        'delete a draft' => [CatalogPermissions::PRODUCT_ARCHIVE, fn () => null, fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct(Px::product()))],
        'archive a variant' => [CatalogPermissions::PRODUCT_UPDATE, fn () => null, fn (string $id, string $second) => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($second))],
        'restore a variant' => [
            CatalogPermissions::PRODUCT_UPDATE,
            fn (string $id, string $second) => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($second)),
            fn (string $id, string $second) => app(RestoreVariantHandler::class)->handle(new RestoreVariant($second)),
        ],
    ]);
});

/**
 * @return array<string, mixed>
 */
function catalogStagesAudit(string $action, string $subjectId): array
{
    return (array) json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->where('subject_id', $subjectId)->orderByDesc('id')->value('changes'), true);
}

describe('what the audit log keeps', function () {
    it('reads from what was to what is', function () {
        [$id, $variant] = catalogStagesWhole();
        $photo = Cx::media();

        app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($variant, [$photo]));
        app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($variant, '1307'));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
        app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id));
        app(RestoreProductHandler::class)->handle(new RestoreProduct($id));

        expect(catalogStagesAudit('catalog.variant.added', $variant))->toMatchArray(['product_id' => [null, $id], 'code' => [null, '1304']])
            ->and(catalogStagesAudit('catalog.variant.photos_changed', $variant))->toBe(['media_ids' => [null, $photo]])
            ->and(catalogStagesAudit('catalog.variant.code_corrected', $variant))->toBe(['code' => ['1304', '1307']])
            ->and(catalogStagesAudit('catalog.product.made_ready', $id))->toBe(['stage' => ['DRAFT', 'READY']])
            ->and(catalogStagesAudit('catalog.product.restored', $id))->toBe(['stage' => ['ARCHIVED', 'READY']]);
    });
});

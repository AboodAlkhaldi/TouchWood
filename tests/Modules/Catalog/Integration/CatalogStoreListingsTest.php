<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
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
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabel;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabelHandler;
use Modules\Catalog\Application\Command\DeleteLabel\DeleteLabel;
use Modules\Catalog\Application\Command\DeleteLabel\DeleteLabelHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
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
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\InvalidSellingTerms;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemInUse;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NotChosenInStore;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Each store's choice (catalog.md §1.3, §3, §4.2, amendment 4): a whole product or single variants,
| switched on or off by the store's own people; selling terms, "Not available now" and labels for a
| product the store has chosen; archiving switching it off everywhere; a store that is off prepared
| before it opens.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Cx::actAsStaffWith([
        CatalogPermissions::LISTING_CHOOSE,
        CatalogPermissions::LISTING_SELLING,
        CatalogPermissions::LISTING_UNAVAILABLE,
        CatalogPermissions::LISTING_LABELS,
    ]);
});

/**
 * @return list<string> the variants switched on in the store, in id order
 */
function catalogListingActive(string $storeId, string $productId): array
{
    return array_values(array_map('strval', DB::table('catalog.store_variants')->where('store_id', $storeId)->where('product_id', $productId)->where('is_active', true)->orderBy('variant_id')->pluck('variant_id')->all()));
}

/**
 * @param  list<mixed>|null  $variantIds
 */
function catalogListingChoose(string $store, string $productId, bool $active = true, ?array $variantIds = null): void
{
    app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $productId, $active, $variantIds));
}

function catalogListingLabel(string $nameEn, int $position = 0): string
{
    return Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('عرض', $nameEn, 'green', $position)));
}

describe('choosing', function () {
    it('takes catalog.listing.choose in that store, and no other', function () {
        $ready = Px::ready();
        Cx::actAsStaffWith([CatalogPermissions::LISTING_CHOOSE], ['sa']);

        catalogListingChoose('sa', $ready['product']);

        expect(catalogListingActive(Fx::storeId('sa'), $ready['product']))->toBe($ready['variants'])
            ->and(fn () => catalogListingChoose('eg', $ready['product']))->toThrow(Unauthorized::class)
            ->and(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore('not-a-store', $ready['product'], true)))->toThrow(Unauthorized::class)
            ->and(Fx::audits('catalog.listing.chosen', $ready['product']))->toBe(1);
    });

    it('answers a store that does not exist as no store', function () {
        $ready = Px::ready();

        expect(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(strtolower((string) Str::ulid()), $ready['product'], true)))->toThrow(InvalidCatalogAttribute::class, 'store');
    });

    it('chooses a whole product: its variants not archived, retail only, from one; one added later is chosen nowhere', function () {
        $ready = Px::ready(['60 cm', '80 cm', '90 cm']);
        [$sixty, $eighty, $ninety] = $ready['variants'];
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($ninety)));

        catalogListingChoose('sa', $ready['product']);
        $later = Fx::asSystem(fn (): string => app(AddVariantHandler::class)->handle(new AddVariant($ready['product'], '7000001', [$ready['width'] => Px::value($ready['width'], '100 cm')])));
        $sa = Fx::storeId('sa');
        $row = DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $sixty)->sole();

        expect(catalogListingActive($sa, $ready['product']))->toBe(collect([$sixty, $eighty])->sort()->values()->all())
            ->and(DB::table('catalog.store_variants')->where('variant_id', $later)->exists())->toBeFalse()
            ->and([(bool) $row->sells_retail, (bool) $row->sells_wholesale])->toBe([true, false])
            ->and(DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $ready['product'])->value('retail_minimum'))->toBe(1);
    });

    it('chooses single variants, switches off keeping the rows, and brings back how they sold', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        $sa = Fx::storeId('sa');

        catalogListingChoose('sa', $ready['product'], true, [$sixty]);
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$sixty => ['retail' => true, 'wholesale' => true]], wholesaleMinimum: 10));
        catalogListingChoose('sa', $ready['product'], false, [$sixty]);
        $off = catalogListingActive($sa, $ready['product']);
        catalogListingChoose('sa', $ready['product'], true, [$sixty]);

        expect($off)->toBe([])
            ->and(catalogListingActive($sa, $ready['product']))->toBe([$sixty])
            ->and((bool) DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $sixty)->value('sells_wholesale'))->toBeTrue()
            ->and(DB::table('catalog.store_variants')->where('variant_id', $eighty)->exists())->toBeFalse();
    });

    it('switches on only a ready product\'s variants, not archived, and only its own', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($eighty)));
        $draft = Px::product();
        $other = Px::ready();

        expect(fn () => catalogListingChoose('sa', $draft))->toThrow(InvalidCatalogAttribute::class, 'product')
            ->and(fn () => catalogListingChoose('sa', $ready['product'], true, [$eighty]))->toThrow(InvalidCatalogAttribute::class, 'variants')
            ->and(fn () => catalogListingChoose('sa', $ready['product'], true, [$other['variants'][0]]))->toThrow(VariantNotFound::class)
            ->and(fn () => catalogListingChoose('sa', strtolower((string) Str::ulid())))->toThrow(ProductNotFound::class)
            ->and(fn () => catalogListingChoose('sa', $ready['product'], true, [7]))->toThrow(InvalidCatalogAttribute::class, 'variants');

        // Switching off what was never chosen changes nothing.
        catalogListingChoose('sa', $draft, false);

        expect(DB::table('catalog.store_products')->count())->toBe(0)
            ->and(Fx::audits('catalog.listing.chosen'))->toBe(0)
            ->and($sixty)->toBeString();
    });

    it('prepares a store that is switched off', function () {
        $ready = Px::ready();
        // Its id taken while it is on: an off store's code finds no store.
        $ae = Fx::storeId('ae');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));

        app(ChooseInStoreHandler::class)->handle(new ChooseInStore($ae, $ready['product'], true));

        expect(catalogListingActive($ae, $ready['product']))->toBe($ready['variants']);
    });

    it('tells the modules above which variants a store took up, once', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        Event::fake([StoreListingChanged::class]);

        catalogListingChoose('sa', $ready['product'], true, [$ready['variants'][0]]);
        catalogListingChoose('sa', $ready['product']);
        catalogListingChoose('sa', $ready['product']);
        catalogListingChoose('sa', $ready['product'], false);

        Event::assertDispatchedTimes(StoreListingChanged::class, 2);
        Event::assertDispatched(StoreListingChanged::class, fn (StoreListingChanged $event): bool => $event->storeId === Fx::storeId('sa') && $event->variantIds === [$ready['variants'][1]]);
    });
});

describe('selling terms', function () {
    it('keeps a mode for every variant, each limit 1 to 100,000, a maximum never below its minimum, wholesale with its minimum', function (array $terms, string $rule) {
        $ready = Px::ready();
        $sa = Fx::storeId('sa');
        catalogListingChoose('sa', $ready['product']);
        $variant = $ready['variants'][0];

        expect(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], ...catalogListingTerms($terms, $variant))))->toThrow(InvalidSellingTerms::class, $rule);
    })->with([
        'no mode' => [['modes' => ['retail' => false, 'wholesale' => false]], 'a selling mode'],
        'wholesale with no minimum' => [['modes' => ['retail' => true, 'wholesale' => true]], 'a wholesale minimum'],
        'a retail maximum below its minimum' => [['retailMinimum' => 5, 'retailMaximum' => 4], 'retail maximum'],
        'a wholesale maximum below its minimum' => [['wholesaleMinimum' => 10, 'wholesaleMaximum' => 9], 'wholesale maximum'],
        'a minimum of nothing' => [['retailMinimum' => 0], 'from 1 to 100000'],
        'a maximum past the range' => [['retailMaximum' => 100_001], 'from 1 to 100000'],
    ]);

    it('sets them for a product the store chose, audited, and nothing for none', function () {
        $ready = Px::ready();
        $sa = Fx::storeId('sa');
        $variant = $ready['variants'][0];

        expect(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'])))->toThrow(NotChosenInStore::class);

        catalogListingChoose('sa', $ready['product']);
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$variant => ['retail' => false, 'wholesale' => true]], 2, 50, 10, 500));
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$variant => ['retail' => false, 'wholesale' => true]], 2, 50, 10, 500));
        $row = DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $ready['product'])->sole();

        expect([$row->retail_minimum, $row->retail_maximum, $row->wholesale_minimum, $row->wholesale_maximum])->toBe([2, 50, 10, 500])
            ->and(Fx::audits('catalog.listing.terms_set', $ready['product']))->toBe(1)
            ->and(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$variant => 'retail'])))->toThrow(InvalidCatalogAttribute::class, 'modes')
            ->and(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [strtolower((string) Str::ulid()) => ['retail' => true, 'wholesale' => false]])))->toThrow(VariantNotFound::class)
            ->and(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$variant => ['retail' => 'yes', 'wholesale' => false]])))->toThrow(InvalidCatalogAttribute::class, 'modes');
    });
});

/**
 * @param  array<string, mixed>  $terms
 * @return array<string, mixed> SetSellingTerms's named arguments after the store and the product
 */
function catalogListingTerms(array $terms, string $variant): array
{
    $named = [];

    if (isset($terms['modes'])) {
        $named['modes'] = [$variant => $terms['modes']];
    }

    foreach (['retailMinimum', 'retailMaximum', 'wholesaleMinimum', 'wholesaleMaximum'] as $limit) {
        if (array_key_exists($limit, $terms)) {
            $named[$limit] = $terms[$limit];
        }
    }

    return $named;
}

describe('"Not available now"', function () {
    it('marks a product, every variant with it, or one variant, in that store only, under its own job', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty] = $ready['variants'];
        [$sa, $eg] = [Fx::storeId('sa'), Fx::storeId('eg')];
        catalogListingChoose('sa', $ready['product']);
        catalogListingChoose('eg', $ready['product']);

        app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($sa, $ready['product']));
        app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($eg, $ready['product'], $sixty));

        expect((bool) DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $ready['product'])->value('not_available_now'))->toBeTrue()
            ->and((bool) DB::table('catalog.store_products')->where('store_id', $eg)->where('product_id', $ready['product'])->value('not_available_now'))->toBeFalse()
            ->and(DB::table('catalog.store_variants')->where('not_available_now', true)->pluck('variant_id')->all())->toBe([$sixty])
            ->and(Fx::audits('catalog.listing.unavailable_marked', $ready['product']))->toBe(2);

        app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow($sa, $ready['product']));
        app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow($sa, $ready['product']));

        app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow($eg, $ready['product'], $sixty));

        expect((bool) DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $ready['product'])->value('not_available_now'))->toBeFalse()
            ->and(DB::table('catalog.store_variants')->where('not_available_now', true)->count())->toBe(0)
            ->and(Fx::audits('catalog.listing.unavailable_cleared', $ready['product']))->toBe(2);

        Cx::actAsStaffWith([CatalogPermissions::LISTING_CHOOSE]);

        expect(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($sa, $ready['product'])))->toThrow(Unauthorized::class);
    });

    it('needs a product, or a variant, the store chose', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        $sa = Fx::storeId('sa');

        expect(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($sa, $ready['product'])))->toThrow(NotChosenInStore::class);

        catalogListingChoose('sa', $ready['product'], true, [$sixty]);

        expect(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($sa, $ready['product'], $eighty)))->toThrow(NotChosenInStore::class);
    });
});

describe('labels', function () {
    it('attaches at most ten, each once, active ones newly, keeps one deactivated since, and shows them in the list\'s order', function () {
        $ready = Px::ready();
        $sa = Fx::storeId('sa');
        catalogListingChoose('sa', $ready['product']);
        // Placed in the list against the order they were made in.
        [$sale, $new, $last] = [catalogListingLabel('Sale', 2), catalogListingLabel('New', 1), catalogListingLabel('Last pieces', 3)];

        app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], [$new, $sale]));
        Fx::asSystem(fn () => app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($sale)));
        app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], [$sale, $new]));
        Fx::asSystem(fn () => app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($last)));

        expect(app(StoreListingRepository::class)->of($sa, $ready['product'])->labelIds())->toBe([$new, $sale])
            ->and(Fx::audits('catalog.listing.labels_attached', $ready['product']))->toBe(1)
            ->and(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], [$sale, $new, $last])))->toThrow(ListItemInactive::class)
            ->and(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], [$new, $new])))->toThrow(InvalidCatalogAttribute::class, 'labels')
            ->and(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], array_fill(0, 11, $new))))->toThrow(TooMany::class)
            ->and(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], [strtolower((string) Str::ulid())])))->toThrow(ListItemNotFound::class);
    });

    it('needs a product the store chose, and keeps a label it shows from being deleted', function () {
        $ready = Px::ready();
        $sa = Fx::storeId('sa');
        $sale = catalogListingLabel('Sale');

        expect(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], [$sale])))->toThrow(NotChosenInStore::class);

        catalogListingChoose('sa', $ready['product']);
        app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], [$sale]));

        expect(fn () => Fx::asSystem(fn () => app(DeleteLabelHandler::class)->handle(new DeleteLabel($sale))))->toThrow(ListItemInUse::class);

        app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], []));
        Fx::asSystem(fn () => app(DeleteLabelHandler::class)->handle(new DeleteLabel($sale)));

        expect(DB::table('catalog.labels')->where('id', $sale)->exists())->toBeFalse();
    });
});

describe('archiving', function () {
    it('switches a product off in every store, each audited there, and restoring leaves it off', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        catalogListingChoose('sa', $ready['product']);
        catalogListingChoose('eg', $ready['product']);

        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($ready['product'])));
        Fx::asSystem(fn () => app(RestoreProductHandler::class)->handle(new RestoreProduct($ready['product'])));

        expect(DB::table('catalog.store_variants')->where('is_active', true)->count())->toBe(0)
            ->and(DB::table('catalog.store_variants')->count())->toBe(4)
            ->and(Fx::audits('catalog.listing.chosen', $ready['product']))->toBe(4)
            // Each in its own store.
            ->and(DB::table('platform.audit_entries')->where('action', 'catalog.listing.chosen')->where('subject_id', $ready['product'])->pluck('store_id')->countBy()->sortKeys()->all())
            ->toEqual(collect([Fx::storeId('eg') => 2, Fx::storeId('sa') => 2])->sortKeys()->all());
    });

    it('switches an archived variant off in every store, the others kept on', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        catalogListingChoose('sa', $ready['product']);
        catalogListingChoose('eg', $ready['product']);

        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($sixty)));

        expect(catalogListingActive(Fx::storeId('sa'), $ready['product']))->toBe([$eighty])
            ->and(catalogListingActive(Fx::storeId('eg'), $ready['product']))->toBe([$eighty]);
    });
});

describe('the shared data of a product a store sells', function () {
    it('needs the job in every store where the product is Active; Active nowhere, in any store', function () {
        $ready = Px::ready();
        catalogListingChoose('eg', $ready['product']);
        $words = fn (string $word) => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($ready['product'], [$word]));

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE], ['sa']);

        expect(fn () => $words('slide'))->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE], ['sa', 'eg']);
        $words('slide');
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('eg'), $ready['product'], false)));
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE], ['sa']);
        $words('rail');

        expect(array_column(app(ProductRepository::class)->searchWords($ready['product']), 'word'))->toBe(['rail']);
    });

    it('needs it in every such store to take a deleted photo\'s file out of the product', function () {
        Storage::fake('local');
        $ready = Px::ready();
        $photo = Cx::media();
        Fx::asSystem(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($ready['product'], [...app(ProductRepository::class)->gallery($ready['product']), $photo])));
        catalogListingChoose('eg', $ready['product']);
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE, PlatformPermissions::MEDIA_DELETE], ['sa']);

        expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($photo)))->toThrow(Unauthorized::class)
            ->and(app(ProductRepository::class)->gallery($ready['product']))->toContain($photo);
    });
});

describe('every change to a product\'s shared data', function () {
    it('needs the job in each store that sells it', function (string $permission, Closure $change) {
        $ready = Px::ready(['60 cm', '80 cm']);
        catalogListingChoose('eg', $ready['product']);
        Cx::actAsStaffWith([$permission], ['sa']);

        expect(fn () => $change($ready))->toThrow(Unauthorized::class);
    })->with([
        'its details' => [CatalogPermissions::PRODUCT_UPDATE, function (array $r): void {
            $product = app(ProductRepository::class)->find($r['product']) ?? throw new LogicException('No such product.');
            app(EditProductDetailsHandler::class)->handle(new EditProductDetails(
                $r['product'], 'درج مختلف', $product->name()->en, $product->brandId(),
                descriptionAr: $product->descriptionAr()?->toArray(), descriptionEn: $product->descriptionEn()?->toArray(),
                categoryId: $product->categoryId(), attributeSetId: $product->attributeSetId(),
            ));
        }],
        'a new variant' => [CatalogPermissions::PRODUCT_UPDATE, fn (array $r) => app(AddVariantHandler::class)->handle(new AddVariant($r['product'], '7100001', [$r['width'] => Px::value($r['width'], '100 cm')]))],
        'a variant' => [CatalogPermissions::PRODUCT_UPDATE, fn (array $r) => app(UpdateVariantHandler::class)->handle(new UpdateVariant($r['variants'][0], (string) DB::table('catalog.variants')->where('id', $r['variants'][0])->value('code'), [$r['width'] => Px::value($r['width'], '100 cm')]))],
        'a code' => [CatalogPermissions::VARIANT_CORRECT_CODE, fn (array $r) => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($r['variants'][0], '7100002'))],
        'its gallery' => [CatalogPermissions::PRODUCT_UPDATE, fn (array $r) => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($r['product'], [Cx::media()]))],
        'a variant\'s photos' => [CatalogPermissions::PRODUCT_UPDATE, fn (array $r) => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($r['variants'][0], [Cx::media()]))],
        'its search words' => [CatalogPermissions::PRODUCT_UPDATE, fn (array $r) => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($r['product'], ['slide']))],
        'its filter values' => [CatalogPermissions::PRODUCT_UPDATE, function (array $r): void {
            $finish = Px::attribute('Finish', 'FILTERABLE');
            app(SetFilterValuesHandler::class)->handle(new SetFilterValues($r['product'], [Px::value($finish, 'Oak')]));
        }],
        'its relations' => [CatalogPermissions::PRODUCT_UPDATE, fn (array $r) => app(SetRelationsHandler::class)->handle(new SetRelations($r['product'], 'RELATED', [Px::ready()['product']]))],
        'archiving it' => [CatalogPermissions::PRODUCT_ARCHIVE, fn (array $r) => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($r['product']))],
        'a variant archived' => [CatalogPermissions::PRODUCT_UPDATE, fn (array $r) => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($r['variants'][1]))],
        'a variant restored' => [CatalogPermissions::PRODUCT_UPDATE, function (array $r): void {
            Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($r['variants'][1])));
            app(RestoreVariantHandler::class)->handle(new RestoreVariant($r['variants'][1]));
        }],
    ]);
});

describe('what step 4\'s review found', function () {
    it('writes no row for what a store never chose: switching it off, or archiving a variant it did not take', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        catalogListingChoose('eg', $ready['product'], false);
        catalogListingChoose('sa', $ready['product'], true, [$sixty]);

        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($eighty)));

        expect(DB::table('catalog.store_products')->where('store_id', Fx::storeId('eg'))->exists())->toBeFalse()
            ->and(DB::table('catalog.store_variants')->where('variant_id', $eighty)->exists())->toBeFalse()
            ->and(catalogListingActive(Fx::storeId('sa'), $ready['product']))->toBe([$sixty]);
    });

    it('switches on no archived product', function () {
        $ready = Px::ready();
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($ready['product'])));

        expect(fn () => catalogListingChoose('sa', $ready['product']))->toThrow(InvalidCatalogAttribute::class, 'product');
    });

    it('keeps in the audit log, in the store, what the store\'s choice was and is', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        $ids = $ready['variants'];
        sort($ids);
        catalogListingChoose('sa', $ready['product']);
        $entry = DB::table('platform.audit_entries')->where('action', 'catalog.listing.chosen')->where('subject_id', $ready['product'])->sole();

        expect($entry->store_id)->toBe(Fx::storeId('sa'))
            ->and((array) json_decode((string) $entry->changes, true))->toMatchArray([
                'active_variants' => [null, implode(',', $ids)],
                'retail_variants' => [null, implode(',', $ids)],
            ]);
    });

    it('takes each limit at its bounds, a maximum equal to its minimum', function () {
        $ready = Px::ready();
        $sa = Fx::storeId('sa');
        catalogListingChoose('sa', $ready['product']);

        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$ready['variants'][0] => ['retail' => true, 'wholesale' => true]], 100_000, 100_000, 1, 1));
        $row = DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $ready['product'])->sole();

        expect([$row->retail_minimum, $row->retail_maximum, $row->wholesale_minimum, $row->wholesale_maximum])->toBe([100_000, 100_000, 1, 1])
            ->and(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], wholesaleMaximum: 4)))->toThrow(InvalidSellingTerms::class, 'wholesale maximum');
    });

    it('keeps a wholesale minimum while another variant still sells wholesale, and edits a product switched off', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        $sa = Fx::storeId('sa');
        catalogListingChoose('sa', $ready['product']);
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$sixty => ['retail' => true, 'wholesale' => true]], wholesaleMinimum: 10));
        catalogListingChoose('sa', $ready['product'], false);

        // Switched off, still the store's to edit (amendment 4(e)).
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$sixty => ['retail' => true, 'wholesale' => true]], 2, wholesaleMinimum: 10));

        expect(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$eighty => ['retail' => true, 'wholesale' => false]])))->toThrow(InvalidSellingTerms::class, 'a wholesale minimum')
            ->and(DB::table('catalog.store_products')->where('store_id', $sa)->value('retail_minimum'))->toBe(2);
    });

    it('answers a variant that is not the product\'s as not found, and one the store did not take as not chosen', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        $other = Px::ready();
        $sa = Fx::storeId('sa');
        catalogListingChoose('sa', $ready['product'], true, [$sixty]);

        expect(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow($sa, $ready['product'], $other['variants'][0])))->toThrow(VariantNotFound::class)
            ->and(fn () => app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow($sa, $ready['product'], strtolower((string) Str::ulid()))))->toThrow(VariantNotFound::class)
            ->and(fn () => app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow($sa, $ready['product'], $other['variants'][0])))->toThrow(VariantNotFound::class)
            ->and(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($sa, $ready['product'], [$eighty => ['retail' => true, 'wholesale' => false]])))->toThrow(NotChosenInStore::class);
    });

    it('counts labels before reading any, and takes them by their ids only', function () {
        $ready = Px::ready();
        $sa = Fx::storeId('sa');

        expect(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], array_map(static fn (): string => strtolower((string) Str::ulid()), range(1, 11)))))->toThrow(TooMany::class)
            ->and(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels($sa, $ready['product'], [7])))->toThrow(InvalidCatalogAttribute::class, 'labels');
    });

    it('needs the job in every store that sells a product to take a deleted file out of its variant', function () {
        Storage::fake('local');
        $ready = Px::ready();
        $photo = Cx::media();
        Fx::asSystem(fn () => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($ready['variants'][0], [$photo])));
        catalogListingChoose('eg', $ready['product']);
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE, PlatformPermissions::MEDIA_DELETE], ['sa']);

        expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($photo)))->toThrow(Unauthorized::class);
    });

    it('takes the products\' lock before a list\'s when a deleted file is a logo and a product\'s photo', function () {
        Storage::fake('local');
        $ready = Px::ready();
        $photo = Cx::media();
        Fx::asSystem(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($ready['product'], [...app(ProductRepository::class)->gallery($ready['product']), $photo])));
        DB::table('catalog.brands')->where('id', DB::table('catalog.products')->where('id', $ready['product'])->value('brand_id'))->update(['logo_media_id' => $photo]);
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE, CatalogPermissions::BRAND_MANAGE, PlatformPermissions::MEDIA_DELETE]);
        $locks = Cx::recordLocks();

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($photo));

        expect(array_values(array_unique(array_column((array) $locks, 'key'))))->toBe(['catalog:products', 'catalog:brands']);
    });
});

describe('each listing change\'s own job (review of step 7)', function () {
    it('needs its own job in that store: no other job, nor the job in another store, will do', function (string $permission, Closure $change) {
        $ready = Px::ready();
        $label = catalogListingLabel('Offer');
        catalogListingChoose('sa', $ready['product']);
        $all = [CatalogPermissions::LISTING_CHOOSE, CatalogPermissions::LISTING_SELLING, CatalogPermissions::LISTING_UNAVAILABLE, CatalogPermissions::LISTING_LABELS];

        Cx::actAsStaffWith(array_values(array_diff($all, [$permission])));
        expect(fn () => $change($ready, $label))->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([$permission], ['eg']);
        expect(fn () => $change($ready, $label))->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([$permission], ['sa']);
        $change($ready, $label);
    })->with([
        'labels' => [CatalogPermissions::LISTING_LABELS, fn (array $ready, string $label) => app(AttachLabelsHandler::class)->handle(new AttachLabels(Fx::storeId('sa'), $ready['product'], [$label]))],
        'selling terms' => [CatalogPermissions::LISTING_SELLING, fn (array $ready) => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $ready['product'], [$ready['variants'][0] => ['retail' => true, 'wholesale' => false]]))],
        '"Not available now"' => [CatalogPermissions::LISTING_UNAVAILABLE, fn (array $ready) => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $ready['product']))],
        'clearing "Not available now"' => [CatalogPermissions::LISTING_UNAVAILABLE, fn (array $ready) => app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow(Fx::storeId('sa'), $ready['product']))],
    ]);
});

describe('"Not available now" on a whole product (§8 #7)', function () {
    it('hides a variant added and chosen later too', function () {
        $ready = Px::ready(['60 cm']);
        catalogListingChoose('sa', $ready['product']);
        app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $ready['product']));
        $later = Fx::asSystem(fn (): string => app(AddVariantHandler::class)->handle(new AddVariant($ready['product'], '7272', [$ready['width'] => Px::value($ready['width'], '80 cm')])));

        catalogListingChoose('sa', $ready['product'], true, [...$ready['variants'], $later]);
        $variant = app(CatalogApi::class)->storeVariant(StoreId::fromString(Fx::storeId('sa')), $later);

        expect([$variant?->isActive, $variant?->orderable, $variant?->notAvailableNow])->toBe([true, false, true]);
    });
});

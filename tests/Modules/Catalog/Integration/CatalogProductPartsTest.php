<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValue;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValueHandler;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttribute;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttributeHandler;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValue;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValueHandler;
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
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemInUse;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What hangs off a product (catalog.md §1.1, §1.2, §1.10, amendment 3): its gallery and each
| variant's photos, its search words, its filter values and its hand-picked relations — each sent
| whole, each change audited by value; and a photo's file deleted from the media library.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE]);
});

/** A product made ready by hand: its readiness rules are step 3c's. */
function catalogPartsReady(string $productId): void
{
    DB::table('catalog.products')->where('id', $productId)->update(['stage' => 'READY', 'category_id' => Px::category()]);
}

/**
 * @return array<string, mixed>
 */
function catalogPartsAudit(string $action): array
{
    return (array) json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->orderByDesc('id')->value('changes'), true);
}

describe('the gallery and a variant\'s photos', function () {
    it('keeps public images in the order given, each once, at most 20 — and 10 for a variant', function () {
        $product = Px::product();
        $variant = Px::variant($product, '1001');
        [$a, $b] = [Cx::media(), Cx::media()];

        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [$b, strtoupper($a)]));

        expect(app(ProductRepository::class)->gallery($product))->toBe([$b, $a])
            ->and(catalogPartsAudit('catalog.product.gallery_changed'))->toBe(['media_ids' => [null, "{$b},{$a}"]])
            ->and(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [$a, $a])))->toThrow(InvalidCatalogAttribute::class, 'photos')
            ->and(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [Cx::media('PRIVATE')])))->toThrow(InvalidCatalogAttribute::class, 'photos')
            ->and(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [Cx::media('PUBLIC', 'application/pdf')])))->toThrow(InvalidCatalogAttribute::class, 'photos')
            ->and(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, array_fill(0, 21, $a))))->toThrow(TooMany::class)
            ->and(fn () => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($variant, array_fill(0, 11, $a))))->toThrow(TooMany::class);

        app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($variant, [$a]));
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [$b, $a]));

        expect(app(VariantRepository::class)->photos($variant))->toBe([$a])
            ->and(Fx::audits('catalog.product.gallery_changed', $product))->toBe(1);
    });
});

describe('search words', function () {
    it('keeps each word as typed and as search reads it, a duplicate once, quietly', function () {
        $product = Px::product();

        app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, ['Slide', 'مفصلة', 'slide', ' مفصله ', 'Rail']));

        expect(app(ProductRepository::class)->searchWords($product))->toBe([
            ['word' => 'Slide', 'normalized' => 'slide'],
            ['word' => 'مفصلة', 'normalized' => 'مفصله'],
            ['word' => 'Rail', 'normalized' => 'rail'],
        ])->and(catalogPartsAudit('catalog.product.search_words_changed'))->toBe(['search_words' => [null, '["Slide","مفصلة","Rail"]']]);

        app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, ['Slide', 'مفصلة', 'Rail', 'RAIL']));

        expect(Fx::audits('catalog.product.search_words_changed', $product))->toBe(1);
    });

    it('takes at most 30 words, each of at most 50 characters as kept', function () {
        $product = Px::product();
        $words = array_map(static fn (int $i): string => "word{$i}", range(1, 31));

        expect(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, $words)))->toThrow(TooMany::class)
            ->and(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, array_fill(0, 301, 'slide'))))->toThrow(TooMany::class)
            // "İ" lowered is two characters: 26 of them are 52 once kept.
            ->and(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, [str_repeat('İ', 26)])))->toThrow(InvalidCatalogAttribute::class, 'search_words')
            ->and(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, [['slide']])))->toThrow(InvalidCatalogAttribute::class, 'search_words');

        // Thirty distinct, and a duplicate besides: accepted.
        app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, [...array_slice($words, 0, 30), 'WORD1']));

        expect(app(ProductRepository::class)->searchWords($product))->toHaveCount(30);
    });
});

describe('filter values', function () {
    it('takes values of filter attributes, several of one attribute, each once', function () {
        $suitable = Px::attribute('Suitable for', 'FILTERABLE');
        $kitchen = Px::value($suitable, 'Kitchen');
        $bathroom = Px::value($suitable, 'Bathroom');
        $width = Px::attribute('Width');
        $sixty = Px::value($width, '60 cm');
        $product = Px::product();

        app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, [$bathroom, $kitchen]));
        $sorted = [$kitchen, $bathroom];
        sort($sorted);

        expect(array_keys(app(ProductRepository::class)->filterValues($product)))->toEqualCanonicalizing([$kitchen, $bathroom])
            ->and(catalogPartsAudit('catalog.product.filter_values_changed'))->toBe(['value_ids' => [null, implode(',', $sorted)]])
            ->and(fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, [$sixty])))->toThrow(InvalidCatalogAttribute::class, 'filter_values')
            ->and(fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, [$kitchen, $kitchen])))->toThrow(InvalidCatalogAttribute::class, 'filter_values')
            ->and(fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, array_fill(0, 101, $kitchen))))->toThrow(TooMany::class);

        // The same values in another order: no change.
        app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, [$kitchen, $bathroom]));

        expect(Fx::audits('catalog.product.filter_values_changed', $product))->toBe(1);
    });

    it('keeps a value deactivated since, takes no newly deactivated one, and keeps the lists from deleting one in use', function () {
        $suitable = Px::attribute('Suitable for', 'FILTERABLE');
        $kitchen = Px::value($suitable, 'Kitchen');
        $bathroom = Px::value($suitable, 'Bathroom');
        $product = Px::product();
        app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, [$kitchen]));
        Fx::asSystem(function () use ($kitchen, $bathroom): void {
            app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($kitchen));
            app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($bathroom));
        });

        app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, [$kitchen]));

        expect(fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, [$kitchen, $bathroom])))->toThrow(ListItemInactive::class)
            ->and(fn () => Fx::asSystem(fn () => app(DeleteAttributeValueHandler::class)->handle(new DeleteAttributeValue($kitchen))))->toThrow(ListItemInUse::class)
            ->and(fn () => Fx::asSystem(fn () => app(DeleteAttributeHandler::class)->handle(new DeleteAttribute($suitable))))->toThrow(ListItemInUse::class);
    });
});

describe('relations', function () {
    it('picks ready products only, in order, each once, never the product itself, at most 20', function () {
        $product = Px::product();
        [$hinge, $plate] = [Px::product('Hinge'), Px::product('Plate')];
        catalogPartsReady($hinge);
        catalogPartsReady($plate);
        $draft = Px::product('Draft');

        app(SetRelationsHandler::class)->handle(new SetRelations($product, 'GOES_WITH', [$plate, $hinge]));

        expect(app(ProductRepository::class)->relations($product, 'GOES_WITH'))->toBe([$plate, $hinge])
            ->and(app(ProductRepository::class)->relations($product, 'RELATED'))->toBe([])
            ->and(catalogPartsAudit('catalog.product.relations_changed'))->toBe(['goes_with' => [null, "{$plate},{$hinge}"]])
            ->and(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', [$draft])))->toThrow(InvalidCatalogAttribute::class, 'relations')
            ->and(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', [$product])))->toThrow(InvalidCatalogAttribute::class, 'relations')
            ->and(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', [$hinge, $hinge])))->toThrow(InvalidCatalogAttribute::class, 'relations')
            ->and(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', ['01j8z3k4m5n6p7q8r9s0t1v2w3'])))->toThrow(ProductNotFound::class)
            ->and(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'SIMILAR', [$hinge])))->toThrow(InvalidCatalogAttribute::class, 'kind')
            ->and(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', array_fill(0, 21, $hinge))))->toThrow(TooMany::class);
    });

    it('keeps a product archived since it was picked, and picks no archived one anew', function () {
        $product = Px::product();
        [$hinge, $plate] = [Px::product('Hinge'), Px::product('Plate')];
        catalogPartsReady($hinge);
        catalogPartsReady($plate);
        app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', [$hinge]));
        DB::table('catalog.products')->whereIn('id', [$hinge, $plate])->update(['stage' => 'ARCHIVED']);

        app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', [$hinge]));

        expect(app(ProductRepository::class)->relations($product, 'RELATED'))->toBe([$hinge])
            ->and(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', [$hinge, $plate])))->toThrow(InvalidCatalogAttribute::class, 'relations');
    });
});

describe('a photo\'s file deleted from the media library', function () {
    beforeEach(function () {
        Storage::fake('local');
    });

    it('takes it out of the gallery and the variant\'s photos, audited', function () {
        $product = Px::product();
        $variant = Px::variant($product, '1001');
        $photo = Cx::media();
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [$photo]));
        app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($variant, [$photo]));
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE, PlatformPermissions::MEDIA_DELETE]);

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($photo));

        expect(app(ProductRepository::class)->gallery($product))->toBe([])
            ->and(app(VariantRepository::class)->photos($variant))->toBe([])
            ->and(catalogPartsAudit('catalog.product.photo_detached'))->toBe(['media_id' => [$photo, null]])
            ->and(Fx::audits('catalog.variant.photo_detached', $variant))->toBe(1);
    });

    it('refuses deleting the last ready photo of a ready product, and lets another go', function () {
        $product = Px::product();
        [$ready, $other, $pending] = [Cx::media(), Cx::media(), Cx::media()];
        DB::table('platform.media')->where('id', $pending)->update(['variants_status' => 'PENDING']);
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [$ready, $other, $pending]));
        catalogPartsReady($product);
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE, PlatformPermissions::MEDIA_DELETE]);

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($other));

        // Two ready photos were left; now one, and a photo still being sized does not count.
        expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($ready)))->toThrow(MediaInUse::class)
            ->and(app(ProductRepository::class)->gallery($product))->toBe([$ready, $pending]);

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($pending));

        expect(app(ProductRepository::class)->gallery($product))->toBe([$ready]);
    });

    it('refuses the delete for someone who may not change products', function () {
        $product = Px::product();
        $photo = Cx::media();
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [$photo]));
        Cx::actAsStaffWith([PlatformPermissions::MEDIA_DELETE]);

        expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($photo)))->toThrow(Unauthorized::class)
            ->and(app(ProductRepository::class)->gallery($product))->toBe([$photo]);
    });
});

describe('what the database refuses behind the code', function () {
    it('refuses a product related to itself, a kind it does not know, and a filter value of another attribute', function () {
        $product = Px::product();
        $other = Px::product('Other');
        $suitable = Px::attribute('Suitable for', 'FILTERABLE');
        $width = Px::attribute('Width', 'FILTERABLE');
        $kitchen = Px::value($suitable, 'Kitchen');

        expect(fn () => DB::transaction(fn () => DB::table('catalog.product_relations')->insert(['product_id' => $product, 'related_id' => $product, 'kind' => 'RELATED', 'position' => 0])))
            ->toThrow(QueryException::class, 'product_relations_not_itself')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.product_relations')->insert(['product_id' => $product, 'related_id' => $other, 'kind' => 'SIMILAR', 'position' => 0])))
            ->toThrow(QueryException::class, 'product_relations_kind')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.product_filter_values')->insert(['product_id' => $product, 'attribute_id' => $width, 'value_id' => $kitchen])))
            ->toThrow(QueryException::class, 'product_filter_values_value')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.product_search_words')->insert(['product_id' => $product, 'normalized' => ' ', 'word' => ' ', 'position' => 0])))
            ->toThrow(QueryException::class, 'product_search_words_present');
    });
});

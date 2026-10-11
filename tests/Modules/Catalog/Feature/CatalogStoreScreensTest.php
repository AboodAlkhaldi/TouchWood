<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Catalog's store screens over HTTP (catalog.md §4.4 S9 "Stores", S10, P6): a product's Stores tab - a
| card for each store the reader covers, a Super Admin's off stores too -, what each of its controls
| changes in one store under its own job, read only to anyone else; and each page's own queries,
| recorded under the admin budget of 15.
|
| The use cases have their own tests; these are about the screens.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

/** Signed in through the panel's door as this staff member. */
function catalogStoreScreensAs(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.3.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * Signed in with these jobs ('*' for All stores).
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function catalogStoreScreens(array $permissions, array $stores = ['*']): AdminBrowser
{
    return catalogStoreScreensAs(Fx::staffWith($permissions, $stores));
}

/**
 * @param  TestResponse<Response>  $response
 */
function catalogStoreScreensToast(TestResponse $response): mixed
{
    return AdminBrowser::flashed($response, 'status');
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function catalogStoreScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

function catalogStoreScreensOn(string $store, string $productId): void
{
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $productId, true)));
}

/** A label - its names one or two words, as labels are. */
function catalogStoreScreensLabel(string $nameEn): string
{
    return Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel("وسم {$nameEn}", $nameEn, 'green', 10)));
}

/** A store's card on the tab, by its code. */
function catalogStoreScreensCard(TestResponse $response, string $code): ?array
{
    $card = null;

    $response->assertInertia(function (AssertableInertia $page) use (&$card, $code): void {
        foreach ($page->toArray()['props']['stores'] ?? [] as $store) {
            if ($store['code'] === $code) {
                $card = $store;
            }
        }
    });

    return $card;
}

/**
 * How many queries the page asks for itself, opened a second time (warm): those made while Catalog's
 * own code is running - the panel's frame around it is counted apart (CatalogListScreensTest).
 */
function catalogStoreScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Catalog/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

describe('a product\'s Stores tab', function () {
    it('shows a card for each store the reader covers, what it does there, and what the reader may do', function () {
        ['product' => $product, 'variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        catalogStoreScreensOn('eg', $product);
        $label = catalogStoreScreensLabel('New');
        DB::table('catalog.store_product_labels')->insert(['store_id' => Fx::storeId('eg'), 'product_id' => $product, 'label_id' => $label]);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE], ['eg', 'sa']);

        $opened = $browser->get("/admin/products/{$product}?tab=stores");
        $opened->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Admin/Products/StoresTab')
            ->where('tab', 'stores')
            ->has('stores', 2)
            ->has('variants', 2)
            ->where('labels.0.id', $label)
        );

        $eg = catalogStoreScreensCard($opened, 'eg');
        $sa = catalogStoreScreensCard($opened, 'sa');

        expect($eg)->not->toBeNull()
            ->and([$eg['chosen'] ?? null, $eg['labelIds'] ?? null, $eg['mayChoose'] ?? null, $eg['maySell'] ?? null])->toBe([true, [$label], true, false])
            ->and(collect($eg['variants'] ?? [])->pluck('on', 'variantId')->all())->toEqual([$sixty => true, $eighty => true])
            ->and(collect($eg['variants'] ?? [])->pluck('retail', 'variantId')->all())->toEqual([$sixty => true, $eighty => true])
            ->and([$sa['chosen'] ?? null, $sa['variants'] ?? null])->toBe([false, []]);
    });

    it('shows a Super Admin every store, the ones that are off marked', function () {
        ['product' => $product] = Px::ready();
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));
        $browser = catalogStoreScreensAs(Fx::staff(superAdmin: true));

        $opened = $browser->get("/admin/products/{$product}?tab=stores");
        $ae = catalogStoreScreensCard($opened, 'ae');

        expect($ae)->not->toBeNull()
            ->and([$ae['active'] ?? null, $ae['mayChoose'] ?? null])->toBe([false, true])
            ->and(catalogStoreScreensCard(catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE])->get("/admin/products/{$product}?tab=stores"), 'ae'))->toBeNull();
    });

    it('switches a ready product on and off in a store, whole or a variant at a time, and never a draft', function () {
        ['product' => $product, 'variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        $draft = Px::product('Hinge');
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE], ['eg']);
        $eg = Fx::storeId('eg');
        $on = fn (): array => DB::table('catalog.store_variants')->where('store_id', $eg)->where('product_id', $product)->pluck('is_active', 'variant_id')->all();

        expect(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/choose", ['active' => true])))->toBe('Switched on in the store')
            ->and($on())->toEqual([$sixty => true, $eighty => true])
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/choose", ['active' => false, 'variant_ids' => [$eighty]])))->toBe('Switched off in the store')
            ->and($on())->toEqual([$sixty => true, $eighty => false])
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$draft}/stores/eg/choose", ['active' => true])))->not->toBeNull()
            ->and(DB::table('catalog.store_products')->where('product_id', $draft)->exists())->toBeFalse();
    });

    it('saves each variant\'s ways of selling with the quantities, and says what they must keep', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_SELLING], ['eg']);
        $eg = Fx::storeId('eg');
        $quantities = ['retail_minimum' => 1, 'retail_maximum' => null, 'wholesale_minimum' => null, 'wholesale_maximum' => null];

        // Wholesale needs a wholesale minimum: refused, nothing changed.
        expect(AdminBrowser::formError($browser->post("/admin/products/{$product}/stores/eg/selling", [...$quantities, 'modes' => [$variant => ['retail' => true, 'wholesale' => true]]])))->not->toBeNull()
            ->and(DB::table('catalog.store_variants')->where('variant_id', $variant)->value('sells_wholesale'))->toBeFalse()
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/selling", [...$quantities, 'wholesale_minimum' => 10, 'modes' => [$variant => ['retail' => true, 'wholesale' => true]]])))->toBe('Selling terms saved')
            ->and(DB::table('catalog.store_variants')->where('variant_id', $variant)->value('sells_wholesale'))->toBeTrue()
            ->and(DB::table('catalog.store_products')->where('product_id', $product)->where('store_id', $eg)->value('wholesale_minimum'))->toBe(10)
            // The quantities alone, as the form saves them, keep the ways of selling.
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/selling", ['retail_minimum' => '2', 'retail_maximum' => '50', 'wholesale_minimum' => '10', 'wholesale_maximum' => ''])))->toBe('Selling terms saved')
            ->and(DB::table('catalog.store_products')->where('product_id', $product)->where('store_id', $eg)->first(['retail_minimum', 'retail_maximum']))->toEqual((object) ['retail_minimum' => 2, 'retail_maximum' => 50])
            ->and(DB::table('catalog.store_variants')->where('variant_id', $variant)->value('sells_wholesale'))->toBeTrue();
    });

    it('marks the whole product or a variant not available now, and available again', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_UNAVAILABLE], ['eg']);
        $eg = Fx::storeId('eg');

        expect(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/unavailable", ['unavailable' => true])))->toBe('Marked not available now')
            ->and(DB::table('catalog.store_products')->where('product_id', $product)->value('not_available_now'))->toBeTrue()
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/unavailable", ['unavailable' => false])))->toBe('Available again')
            ->and(DB::table('catalog.store_products')->where('product_id', $product)->value('not_available_now'))->toBeFalse()
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/unavailable", ['unavailable' => true, 'variant_id' => $variant])))->toBe('Marked not available now')
            ->and(DB::table('catalog.store_variants')->where('variant_id', $variant)->value('not_available_now'))->toBeTrue();
    });

    it('puts at most 10 labels on the product in a store', function () {
        ['product' => $product] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        $labels = array_map(fn (int $n): string => catalogStoreScreensLabel("L{$n}"), range(1, 11));
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_LABELS], ['eg']);
        $eg = Fx::storeId('eg');

        expect(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/labels", ['label_ids' => array_slice($labels, 0, 2)])))->toBe('Labels saved')
            ->and(DB::table('catalog.store_product_labels')->where('product_id', $product)->count())->toBe(2)
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$product}/stores/eg/labels", ['label_ids' => $labels])))->not->toBeNull()
            ->and(DB::table('catalog.store_product_labels')->where('product_id', $product)->count())->toBe(2);
    });

    it('is read only in a store where the reader holds none of its jobs, and refuses its changes there', function () {
        ['product' => $product] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        catalogStoreScreensOn('sa', $product);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE, P::LISTING_SELLING, P::LISTING_UNAVAILABLE, P::LISTING_LABELS], ['eg', 'sa']);
        $reader = catalogStoreScreens([P::PRODUCT_VIEW], ['sa']);
        $sa = catalogStoreScreensCard($reader->get("/admin/products/{$product}?tab=stores"), 'sa');
        $saId = Fx::storeId('sa');

        expect([$sa['mayChoose'] ?? null, $sa['maySell'] ?? null, $sa['mayUnavailable'] ?? null, $sa['mayLabel'] ?? null])->toBe([false, false, false, false])
            ->and(catalogStoreScreensCard($browser->get("/admin/products/{$product}?tab=stores"), 'sa')['mayLabel'] ?? null)->toBeTrue()
            ->and(AdminBrowser::formError($reader->post("/admin/products/{$product}/stores/sa/choose", ['active' => false])))->toBe(trans('errors.unauthorized.detail', [], 'en'))
            ->and(DB::table('catalog.store_variants')->where('product_id', $product)->where('store_id', $saId)->value('is_active'))->toBeTrue();
    });
});

describe('the query budget (frontend.md §5, P21)', function () {
    it('opens a product\'s Stores tab in its own recorded number of queries, warm, however many stores and rows', function () {
        ['product' => $product, 'width' => $width] = Px::ready(['60 cm', '80 cm']);
        catalogStoreScreensOn('eg', $product);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE, P::LISTING_SELLING, P::LISTING_UNAVAILABLE, P::LISTING_LABELS]);
        $uri = "/admin/products/{$product}?tab=stores";
        $own = catalogStoreScreensOwnQueries($browser, $uri);

        // 28 today: every job asked reads the reader's grants again (two queries each - Access keeps them
        // for no request), and this tab asks nine. Over the budget until that is decided (owner).
        expect($own)->toBe(28)->and($own)->toBeLessThanOrEqual(15);

        // More of everything the tab shows: never one query a store, a variant or a label.
        foreach (range(1, 5) as $n) {
            Px::variant($product, (string) (8000 + $n), [$width => Px::value($width, "{$n}5 cm")]);
            catalogStoreScreensLabel("L{$n}");
        }

        catalogStoreScreensOn('sa', $product);
        catalogStoreScreensOn('ae', $product);

        expect(catalogStoreScreensOwnQueries($browser, $uri))->toBe($own);
    });
});

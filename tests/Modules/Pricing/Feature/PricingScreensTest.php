<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Modules\Pricing\Application\Prices\NeedsAPriceCount;
use Modules\Pricing\Application\PricingPermissions as P;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Shared\Domain\ValueObject\StoreId;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Pricing's screens over HTTP (pricing.md §4.3, stage 5's screens step 3): Prices and its drawer, Offers,
| Category Discounts and a discount's page, Wholesale Prices, Needs a Price, the dialogs' reads and every
| change - what each offers for each job, a store wired to a provider, an off store (a Super Admin's only),
| each page's own queries under the admin budget of 15 (frontend.md §5), each change's toast and a
| refusal said on the screen; and the menu's Pricing group with Needs a Price's count.
|
| The reads and the commands have their own tests; these are about the screens.
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A minute later: an offer or a discount that started "now" is running (a moment is kept to the second).
 */
function pricingScreensLater(): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
}

/** A moment this many days from now, as a form sends it (ISO 8601). */
function pricingScreensIn(int $days): string
{
    return CarbonImmutable::now()->addDays($days)->startOfMinute()->toAtomString();
}

/** Signed in through the panel's door as this staff member, the panel in English. */
function pricingScreensAs(string $staffId): AdminBrowser
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'en']);
    $browser = new AdminBrowser('10.9.5.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * Signed in with these jobs in these stores.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function pricingScreens(array $permissions, array $stores = ['sa']): AdminBrowser
{
    return pricingScreensAs(Fx::staffWith($permissions, $stores));
}

/**
 * A ready product named for the order, switched on in KSA.
 *
 * @param  list<string>  $sizes
 * @return array{product: string, variants: list<string>, width: string}
 */
function pricingScreensProduct(string $nameEn, array $sizes = ['60 cm'], ?string $category = null): array
{
    $ready = Px::ready($sizes, $category);
    DB::table('catalog.products')->where('id', $ready['product'])->update(['name_en' => $nameEn]);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));

    return $ready;
}

/**
 * @param  list<string>  $sizes
 */
function pricingScreensSale(array $sizes, ?string $percent = '10', ?string $startsAt = null, ?string $endsAt = null): string
{
    return Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, $sizes, $percent, null, $startsAt, $endsAt)));
}

function pricingScreensDiscount(string $category, ?string $startsAt = null): string
{
    return Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $category, '10', null, 'BOTH', $startsAt, null)));
}

/**
 * The store sells these sizes wholesale from this minimum, and these bands are kept.
 *
 * @param  list<string>  $sizes
 * @param  array<int, string>  $bands
 */
function pricingScreensWholesale(string $product, array $sizes, int $minimum, array $bands = []): void
{
    Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Rx::storeId('sa'), $product, array_fill_keys($sizes, ['retail' => true, 'wholesale' => true]), 1, null, $minimum, null)));

    foreach ($bands === [] ? [] : $sizes as $size) {
        Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Rx::storeId('sa'), $size, $bands)));
    }
}

/**
 * A page's props, as the page receives them.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function pricingScreensProps(TestResponse $response, string $component): array
{
    $props = [];

    $response->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props, $component): void {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * @param  TestResponse<Response>  $response
 */
function pricingScreensToast(TestResponse $response): mixed
{
    return AdminBrowser::flashed($response, 'status');
}

/**
 * Every error a refusal carried, by key.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function pricingScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/**
 * How many queries the page asks for itself, opened a second time (warm): those made while Pricing's own
 * code runs - the panel's frame around it counted apart (as Catalog's screens count theirs), Needs a Price's
 * count beside the menu entry included (AdminFrameQueryBudgetTest counts a menu entry's count with the frame;
 * here, with the queue faked, a store's count is never kept, so it is counted live each time), and never the
 * cache's own rows.
 */
function pricingScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        // The cache's own rows are not the page's (as Pricing's and Catalog's read tests count them).
        if (str_contains($query->sql, '"cache"')) {
            return;
        }

        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($frames as $frame) {
            if (($frame['class'] ?? '') === NeedsAPriceCount::class) {
                return;
            }
        }

        foreach ($frames as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Pricing/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

/** Every store wired to a provider (Sync binds its own; until then none is). */
function pricingScreensWireAll(): void
{
    app()->instance(StoreWiring::class, new class implements StoreWiring
    {
        public function isWired(StoreId $store): bool
        {
            return true;
        }

        public function wiredStores(): array
        {
            return array_map(static fn ($store): string => $store->id, app(PlatformApi::class)->allStores());
        }
    });
}

/**
 * The Pricing group's entries this person was offered, by key, with their counts.
 *
 * @return array<string, mixed>
 */
function pricingScreensMenu(AdminBrowser $browser): array
{
    $found = [];

    $browser->get('/admin')->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$found): void {
        foreach ($inertia->toArray()['props']['menu'] ?? [] as $group) {
            if (is_array($group) && ($group['key'] ?? null) === 'pricing') {
                foreach ($group['entries'] ?? [] as $entry) {
                    $found[$entry['key']] = $entry['count'] ?? null;
                }
            }
        }
    });

    return $found;
}

describe('Prices (P1)', function () {
    it('shows the store\'s sizes with their prices, and what each job may do there', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00']);

        $editor = pricingScreensProps(pricingScreens([P::PRICE_EDIT])->get('/admin/prices'), 'Pricing/Admin/Prices');
        $wholesale = pricingScreensProps(pricingScreens([P::WHOLESALE_EDIT])->get('/admin/prices'), 'Pricing/Admin/Prices');
        $discounts = pricingScreensProps(pricingScreens([P::CATEGORY_DISCOUNT_MANAGE])->get('/admin/prices'), 'Pricing/Admin/Prices');

        expect($editor['total'])->toBe(2)
            ->and($editor['rows'][0]['size']['variantId'])->toBe($a['variants'][0])
            ->and($editor['rows'][0]['retail']['price']['minor'])->toBe(12000)
            ->and($editor['rows'][0]['retail']['source'])->toBe('STAFF')
            ->and($editor['rows'][1]['retail'])->toBeNull()
            ->and($editor['drawer'])->toBeNull()
            ->and([$editor['storeFrame']['mayEditPrices'], $editor['storeFrame']['mayEditWholesale'], $editor['storeFrame']['mayManageDiscounts']])->toBe([true, false, false])
            ->and([$wholesale['storeFrame']['mayEditPrices'], $wholesale['storeFrame']['mayEditWholesale']])->toBe([false, true])
            ->and([$discounts['storeFrame']['mayEditPrices'], $discounts['storeFrame']['mayManageDiscounts']])->toBe([false, true])
            ->and($editor['storeFrame']['storeCode'])->toBe('sa')
            ->and($editor['storeFrame']['ceilingPercent'])->toBeInt()
            ->and($editor['storeFrame']['wired'])->toBeFalse()
            ->and($editor['translations']['pricing::admin_prices.title'])->toBe('Prices');
    });

    it('filters, searches, and opens a size\'s drawer in the address', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        $browser = pricingScreens([P::PRICE_EDIT]);

        $none = pricingScreensProps($browser->get('/admin/prices?filter=no_price'), 'Pricing/Admin/Prices');
        $drawer = pricingScreensProps($browser->get('/admin/prices?size='.$a['variants'][0]), 'Pricing/Admin/Prices');
        $size = $browser->get('/admin/prices/size?store=sa&size='.$a['variants'][0])->assertOk()->json();

        expect(array_column(array_column($none['rows'], 'size'), 'variantId'))->toBe([$a['variants'][1]])
            ->and($none['filter'])->toBe('NO_PRICE')
            ->and($drawer['drawer']['size']['variantId'])->toBe($a['variants'][0])
            ->and($drawer['drawer']['retail']['price']['minor'])->toBe(12000)
            ->and($size['retail']['price']['minor'])->toBe(12000);
    });

    it('offers only the stores where the reader holds a job, refuses another, and shows an off store to a Super Admin only, marked', function () {
        pricingScreensProduct('Alpha');
        $browser = pricingScreens([P::PRICE_EDIT], ['sa', 'eg']);

        $page = pricingScreensProps($browser->get('/admin/prices'), 'Pricing/Admin/Prices');
        $browser->get('/admin/prices?store=ae')->assertForbidden();

        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $staff = pricingScreensProps($browser->get('/admin/prices'), 'Pricing/Admin/Prices');
        $super = pricingScreensAs(Fx::staff(superAdmin: true));
        $off = pricingScreensProps($super->get('/admin/prices?store=eg'), 'Pricing/Admin/Prices');

        expect(array_column($page['storeFrame']['stores'], 'code'))->toBe(['sa', 'eg'])
            ->and(array_column($staff['storeFrame']['stores'], 'code'))->toBe(['sa'])
            ->and($off['storeFrame']['off'])->toBeTrue()
            ->and($off['storeFrame']['storeCode'])->toBe('eg')
            ->and(array_column($off['storeFrame']['stores'], 'isActive', 'code')['eg'] ?? null)->toBeFalse();

        $browser->get('/admin/prices?store=eg')->assertForbidden();
    });

    it('says a wired store\'s prices come from its provider', function () {
        pricingScreensProduct('Alpha');
        pricingScreensWireAll();

        $page = pricingScreensProps(pricingScreens([P::PRICE_EDIT])->get('/admin/prices'), 'Pricing/Admin/Prices');

        expect($page['storeFrame']['wired'])->toBeTrue()
            ->and($page['storeFrame']['reasons']['wiredPrice'])->toBe('The provider sets this price.');
    });

    it('reads its own queries within the budget', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm', '100 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00', $a['variants'][1] => '130.00']);
        $browser = pricingScreens([P::PRICE_EDIT, P::WHOLESALE_EDIT, P::CATEGORY_DISCOUNT_MANAGE]);

        // Recorded (frontend.md §5): a page that grows fails even under the ceiling of 15.
        expect(pricingScreensOwnQueries($browser, '/admin/prices'))->toBe(10);
    });

    it('saves the prices typed with a toast, marks the rows a refusal names, and removes a price', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        $browser = pricingScreens([P::PRICE_EDIT]);
        [$sixty, $eighty] = $a['variants'];

        $saved = $browser->post('/admin/prices', ['store' => 'sa', 'prices' => [$sixty => '120.00', $eighty => '130.00']]);
        $refused = $browser->post('/admin/prices', ['store' => 'sa', 'prices' => [$sixty => '99.999']]);
        $removed = $browser->post('/admin/prices/remove', ['store' => 'sa', 'sizes' => [$eighty]]);
        $noJob = pricingScreens([P::WHOLESALE_EDIT])->post('/admin/prices', ['store' => 'sa', 'prices' => [$sixty => '1.00']]);

        expect(pricingScreensToast($saved))->toBe('Prices saved')
            ->and(pricingScreensErrors($refused))->toHaveKey("rows.{$sixty}")
            ->and(pricingScreensErrors($refused))->toHaveKey('form')
            ->and(Rx::retail('sa', $sixty)?->amount_minor)->toBe(12000)
            ->and(pricingScreensToast($removed))->toBe('Price removed')
            ->and(Rx::retail('sa', $eighty))->toBeNull()
            ->and(AdminBrowser::formError($noJob))->not->toBeEmpty();
    });
});

describe('Offers (P2)', function () {
    it('lists the store\'s offers by state, filtered and sorted, and one a link names', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        $running = pricingScreensSale($a['variants']);
        $scheduled = pricingScreensSale($a['variants'], '5', pricingScreensIn(10));
        $browser = pricingScreens([P::PRICE_EDIT]);
        pricingScreensLater();

        $all = pricingScreensProps($browser->get('/admin/offers'), 'Pricing/Admin/Offers');
        $runningOnly = pricingScreensProps($browser->get('/admin/offers?state=running'), 'Pricing/Admin/Offers');
        $newest = pricingScreensProps($browser->get('/admin/offers?sort=newest'), 'Pricing/Admin/Offers');
        $one = pricingScreensProps($browser->get("/admin/offers?sale={$scheduled}"), 'Pricing/Admin/Offers');
        $product = pricingScreensProps($browser->get("/admin/offers?product={$a['product']}"), 'Pricing/Admin/Offers');

        expect(array_column($all['sales'], 'id'))->toBe([$running, $scheduled])
            ->and(array_column($all['sales'], 'state'))->toBe(['RUNNING', 'SCHEDULED'])
            ->and(array_column($runningOnly['sales'], 'id'))->toBe([$running])
            ->and($newest['sort'])->toBe('NEWEST')
            ->and($newest['sales'])->toHaveCount(2)
            ->and(array_column($one['sales'], 'id'))->toBe([$scheduled])
            ->and($product['product']['nameEn'])->toBe('Alpha')
            ->and($all['storeFrame']['mayEditPrices'])->toBeTrue();
    });

    it('answers the dialog\'s reads: the products, their sizes, the note as typed, the filter\'s products, one offer', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        $sale = pricingScreensSale([$a['variants'][0]]);
        $browser = pricingScreens([P::PRICE_EDIT]);

        $products = $browser->get('/admin/offers/products?store=sa&q=Alpha')->assertOk()->json();
        $sizes = $browser->get("/admin/offers/sizes?store=sa&product={$a['product']}")->assertOk()->json();
        $note = $browser->get("/admin/offers/note?store=sa&product={$a['product']}&percent=20")->assertOk()->json();
        $filter = $browser->get('/admin/offers/filter-products?store=sa')->assertOk()->json();
        $view = $browser->get("/admin/offers/{$sale}")->assertOk()->json();

        expect(array_column($products['rows'], 'productId'))->toBe([$a['product']])
            ->and(array_column($sizes, 'variantId'))->toBe($a['variants'])
            ->and($sizes[1]['retail'])->toBeNull()
            ->and($note['sizes'][0]['newPrice']['minor'])->toBe(9600)
            ->and($note['ceilingPercent'])->toBeInt()
            ->and(array_column($note['sales'], 'id'))->toBe([$sale])
            ->and(array_column($filter['rows'], 'productId'))->toBe([$a['product']])
            ->and($view['sale']['id'])->toBe($sale);

        pricingScreens([P::WHOLESALE_EDIT])->get('/admin/offers/products?store=sa')->assertForbidden();
    });

    it('adds, changes, ends and removes an offer, each with its toast, and says a refusal', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        $browser = pricingScreens([P::PRICE_EDIT]);

        $added = $browser->post('/admin/offers', ['store' => 'sa', 'product' => $a['product'], 'sizes' => [], 'percent' => '10']);
        pricingScreensLater();
        $scheduled = pricingScreensSale($a['variants'], '5', pricingScreensIn(10), pricingScreensIn(15));
        $running = (string) DB::table('pricing.sales')->where('id', '!=', $scheduled)->value('id');
        $changed = $browser->post("/admin/offers/{$scheduled}", ['percent' => '6', 'starts_at' => pricingScreensIn(10), 'ends_at' => pricingScreensIn(16)]);
        $endChanged = $browser->post("/admin/offers/{$running}/end-date", ['ends_at' => pricingScreensIn(40)]);
        $refused = $browser->post('/admin/offers', ['store' => 'sa', 'product' => $a['product'], 'percent' => '150']);
        $ended = $browser->post("/admin/offers/{$running}/end");
        $removed = $browser->post("/admin/offers/{$scheduled}/remove");

        expect(pricingScreensToast($added))->toBe('Offer added')
            ->and(pricingScreensToast($changed))->toBe('Offer changed')
            ->and(pricingScreensToast($endChanged))->toBe('Offer changed')
            ->and(DB::table('pricing.sales')->where('id', $running)->value('ends_at'))->not->toBeNull()
            ->and(pricingScreensErrors($refused))->not->toBeEmpty()
            ->and(pricingScreensToast($refused))->toBeNull()
            ->and(pricingScreensToast($ended))->toBe('Offer ended')
            ->and(pricingScreensToast($removed))->toBe('Offer removed')
            ->and(DB::table('pricing.sales')->where('id', $scheduled)->exists())->toBeFalse();
    });

    it('reads its own queries within the budget', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00', $a['variants'][1] => '130.00']);
        pricingScreensSale($a['variants']);
        pricingScreensSale([$a['variants'][0]], '5', pricingScreensIn(10));
        $browser = pricingScreens([P::PRICE_EDIT]);

        expect(pricingScreensOwnQueries($browser, '/admin/offers'))->toBe(10);
    });
});

describe('Category Discounts (P3) and a discount\'s page', function () {
    it('lists the discounts, opens one\'s page with its preview, and answers the dialog\'s reads', function () {
        $category = Px::category('Kitchens');
        $a = pricingScreensProduct('Alpha', ['60 cm'], $category);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        $discount = pricingScreensDiscount($category);
        $browser = pricingScreens([P::CATEGORY_DISCOUNT_MANAGE]);

        $list = pricingScreensProps($browser->get('/admin/category-discounts'), 'Pricing/Admin/CategoryDiscounts');
        $page = pricingScreensProps($browser->get("/admin/category-discounts/{$discount}"), 'Pricing/Admin/CategoryDiscount');
        $categories = $browser->get('/admin/category-discounts/categories?store=sa')->assertOk()->json();
        $leftOut = $browser->get("/admin/category-discounts/left-out?store=sa&category={$category}")->assertOk()->json();
        $preview = $browser->get("/admin/category-discounts/preview?store=sa&category={$category}&percent=20&lines=both")->assertOk()->json();

        expect(array_column($list['discounts'], 'id'))->toBe([$discount])
            ->and($list['storeFrame']['mayManageDiscounts'])->toBeTrue()
            ->and($page['discount']['id'])->toBe($discount)
            ->and($page['preview']['reachedSizes'])->toBe(1)
            ->and($page['storeFrame']['storeCode'])->toBe('sa')
            ->and(array_column($categories, 'id'))->toContain($category)
            ->and(array_column($leftOut['rows'], 'productId'))->toBe([$a['product']])
            ->and($preview['deepestBasisPoints'])->toBe(2000);

        pricingScreens([P::PRICE_EDIT])->get('/admin/category-discounts/categories?store=sa')->assertForbidden();
    });

    it('adds, changes, ends and removes a discount, each with its toast, and says a refusal', function () {
        $category = Px::category('Kitchens');
        $a = pricingScreensProduct('Alpha', ['60 cm'], $category);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        $browser = pricingScreens([P::CATEGORY_DISCOUNT_MANAGE]);

        $added = $browser->post('/admin/category-discounts', ['store' => 'sa', 'category' => $category, 'percent' => '10', 'lines' => 'RETAIL']);
        pricingScreensLater();
        $running = (string) DB::table('pricing.category_discounts')->value('id');
        $scheduled = pricingScreensDiscount($category, pricingScreensIn(10));
        $changed = $browser->post("/admin/category-discounts/{$scheduled}", ['category' => $category, 'percent' => '12', 'lines' => 'BOTH', 'starts_at' => pricingScreensIn(11)]);
        $endChanged = $browser->post("/admin/category-discounts/{$running}/end-date", ['ends_at' => pricingScreensIn(40)]);
        $refused = $browser->post('/admin/category-discounts', ['store' => 'sa', 'category' => $category, 'percent' => '10']);
        $ended = $browser->post("/admin/category-discounts/{$running}/end");
        $removed = $browser->post("/admin/category-discounts/{$scheduled}/remove", ['list_store' => 'sa']);

        expect(pricingScreensToast($added))->toBe('Discount added')
            ->and(pricingScreensToast($changed))->toBe('Discount changed')
            ->and(pricingScreensToast($endChanged))->toBe('Discount changed')
            ->and(pricingScreensErrors($refused))->not->toBeEmpty()
            ->and(pricingScreensToast($ended))->toBe('Discount ended')
            ->and(pricingScreensToast($removed))->toBe('Discount removed');

        $removed->assertRedirect('/admin/category-discounts?store=sa');
    });

    it('reads its own queries within the budget, the list and a discount\'s page', function () {
        $category = Px::category('Kitchens');
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm'], $category);
        Rx::set('sa', [$a['variants'][0] => '120.00', $a['variants'][1] => '130.00']);
        $discount = pricingScreensDiscount($category);
        $browser = pricingScreens([P::CATEGORY_DISCOUNT_MANAGE]);

        expect(pricingScreensOwnQueries($browser, '/admin/category-discounts'))->toBe(5)
            ->and(pricingScreensOwnQueries($browser, "/admin/category-discounts/{$discount}"))->toBe(14);
    });
});

describe('Wholesale Prices (P4)', function () {
    it('lists the sizes with bands, saves and removes bands with their toasts, refuses a band out of order, and finds a size to add', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00', $a['variants'][1] => '130.00']);
        pricingScreensWholesale($a['product'], $a['variants'], 5);
        pricingScreensWholesale($a['product'], [$a['variants'][0]], 5, [5 => '100.00']);
        $browser = pricingScreens([P::WHOLESALE_EDIT]);

        $page = pricingScreensProps($browser->get('/admin/wholesale-prices'), 'Pricing/Admin/WholesalePrices');
        $sizes = $browser->get('/admin/wholesale-prices/sizes?store=sa')->assertOk()->json();
        $saved = $browser->post('/admin/wholesale-prices', ['store' => 'sa', 'size' => $a['variants'][1], 'bands' => [['from' => '5', 'price' => '110.00'], ['from' => '10', 'price' => '100.00']]]);
        $refused = $browser->post('/admin/wholesale-prices', ['store' => 'sa', 'size' => $a['variants'][1], 'bands' => [['from' => '5', 'price' => '110.00'], ['from' => '10', 'price' => '115.00']]]);
        $removed = $browser->post('/admin/wholesale-prices', ['store' => 'sa', 'size' => $a['variants'][0], 'bands' => []]);

        expect(array_column(array_column($page['rows'], 'size'), 'variantId'))->toBe([$a['variants'][0]])
            ->and($page['rows'][0]['bands'][0]['fromQuantity'])->toBe(5)
            ->and($page['rows'][0]['minimum'])->toBe(5)
            ->and(array_column($sizes['rows'], 'variantId'))->toBe([$a['variants'][1]])
            ->and(pricingScreensToast($saved))->toBe('Wholesale prices saved')
            ->and(AdminBrowser::formError($refused))->not->toBeEmpty()
            ->and(pricingScreensToast($removed))->toBe('Wholesale prices removed')
            ->and(DB::table('pricing.wholesale_bands')->where('variant_id', $a['variants'][0])->exists())->toBeFalse();
    });

    it('reads its own queries within the budget', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00', $a['variants'][1] => '130.00']);
        pricingScreensWholesale($a['product'], $a['variants'], 5, [5 => '100.00']);

        expect(pricingScreensOwnQueries(pricingScreens([P::WHOLESALE_EDIT]), '/admin/wholesale-prices'))->toBe(8);
    });
});

describe('Needs a Price (P5)', function () {
    it('lists the sizes with no price for whoever edits prices, and no one else', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00']);

        $page = pricingScreensProps(pricingScreens([P::PRICE_EDIT])->get('/admin/needs-a-price'), 'Pricing/Admin/NeedsAPrice');
        pricingScreens([P::WHOLESALE_EDIT])->get('/admin/needs-a-price')->assertForbidden();

        expect(array_column($page['rows'], 'variantId'))->toBe([$a['variants'][1]])
            ->and($page['total'])->toBe(1);
    });

    it('reads its own queries within the budget', function () {
        pricingScreensProduct('Alpha', ['60 cm', '80 cm', '100 cm']);

        expect(pricingScreensOwnQueries(pricingScreens([P::PRICE_EDIT]), '/admin/needs-a-price'))->toBe(5);
    });
});

describe('a product\'s Stores tab', function () {
    it('draws Pricing\'s part of each card the reader prices in, its words and its store\'s money and clock with it', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        $browser = pricingScreens([CatalogPermissions::PRODUCT_VIEW, P::PRICE_EDIT]);
        $card = null;

        $browser->get("/admin/products/{$a['product']}?tab=stores")->assertOk()->assertInertia(function (AssertableInertia $page) use (&$card): void {
            foreach ($page->toArray()['props']['stores'] ?? [] as $store) {
                foreach ($store['code'] === 'sa' ? $store['sections'] : [] as $section) {
                    $card = $section['module'] === 'pricing' ? $section : $card;
                }
            }
        });

        expect($card['component'] ?? null)->toBe('Pricing/ProductPrices')
            ->and($card['data']['mayEditPrices'])->toBeTrue()
            ->and($card['data']['sizes'][0]['retail']['price']['minor'])->toBe(12000)
            ->and($card['data']['decimals'])->toBe(2)
            ->and($card['data']['currency'])->toBe('SAR')
            ->and($card['data']['timezone'])->toBe('Asia/Riyadh')
            ->and($card['data']['words']['pricing::admin.section.title'])->toBe('Prices');
    });
});

describe('the guards (the review of step 3)', function () {
    it('refuses another store\'s offer and discount, read or changed, and changes nothing', function () {
        $category = Px::category('Kitchens');
        $a = pricingScreensProduct('Alpha', ['60 cm'], $category);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        $running = pricingScreensSale($a['variants']);
        $scheduled = pricingScreensSale($a['variants'], '5', pricingScreensIn(10));
        $discount = pricingScreensDiscount($category, pricingScreensIn(10));
        $other = pricingScreens([P::PRICE_EDIT, P::WHOLESALE_EDIT, P::CATEGORY_DISCOUNT_MANAGE], ['eg']);
        pricingScreensLater();

        $other->get("/admin/offers/{$running}")->assertForbidden();
        $other->get("/admin/category-discounts/{$discount}")->assertForbidden();

        $posts = [
            "/admin/offers/{$scheduled}" => ['percent' => '6'],
            "/admin/offers/{$running}/end-date" => ['ends_at' => pricingScreensIn(40)],
            "/admin/offers/{$running}/end" => [],
            "/admin/offers/{$scheduled}/remove" => [],
            "/admin/category-discounts/{$discount}" => ['category' => $category, 'percent' => '12', 'lines' => 'BOTH'],
            "/admin/category-discounts/{$discount}/end-date" => ['ends_at' => pricingScreensIn(40)],
            "/admin/category-discounts/{$discount}/end" => [],
            "/admin/category-discounts/{$discount}/remove" => [],
        ];

        foreach ($posts as $uri => $data) {
            $response = $other->post($uri, $data);

            expect(AdminBrowser::formError($response))->not->toBeEmpty("{$uri} was not refused")
                ->and(pricingScreensToast($response))->toBeNull();
        }

        expect(DB::table('pricing.sales')->whereIn('id', [$running, $scheduled])->count())->toBe(2)
            ->and(DB::table('pricing.sales')->where('id', $running)->value('ends_at'))->toBeNull()
            ->and(DB::table('pricing.category_discounts')->where('id', $discount)->exists())->toBeTrue();
    });

    it('refuses a change that names no store', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm']);
        $browser = pricingScreens([P::PRICE_EDIT, P::WHOLESALE_EDIT, P::CATEGORY_DISCOUNT_MANAGE]);

        foreach ([
            '/admin/prices' => ['prices' => [$a['variants'][0] => '120.00']],
            '/admin/prices/remove' => ['sizes' => [$a['variants'][0]]],
            '/admin/offers' => ['product' => $a['product'], 'percent' => '10'],
            '/admin/category-discounts' => ['category' => Px::category('Kitchens'), 'percent' => '10', 'lines' => 'BOTH'],
            '/admin/wholesale-prices' => ['size' => $a['variants'][0], 'bands' => []],
        ] as $uri => $data) {
            expect(AdminBrowser::formError($browser->post($uri, $data)))->not->toBeEmpty("{$uri} was not refused");
        }

        expect(Rx::retail('sa', $a['variants'][0]))->toBeNull();
    });

    it('refuses the dialogs\' reads in a store outside the reader\'s', function () {
        $category = Px::category('Kitchens');
        $a = pricingScreensProduct('Alpha', ['60 cm'], $category);
        $browser = pricingScreens([P::PRICE_EDIT, P::WHOLESALE_EDIT, P::CATEGORY_DISCOUNT_MANAGE], ['sa']);

        foreach ([
            '/admin/prices/size?store=eg&size='.$a['variants'][0],
            "/admin/offers/note?store=eg&product={$a['product']}&percent=10",
            "/admin/category-discounts/preview?store=eg&category={$category}&percent=10&lines=both",
            "/admin/category-discounts/left-out?store=eg&category={$category}",
            '/admin/wholesale-prices/sizes?store=eg',
        ] as $uri) {
            $browser->get($uri)->assertForbidden();
        }
    });

    it('refuses two bands from the same quantity, and Left Out of a category that is off', function () {
        $category = Px::category('Kitchens');
        $a = pricingScreensProduct('Alpha', ['60 cm'], $category);
        Rx::set('sa', [$a['variants'][0] => '120.00']);
        pricingScreensWholesale($a['product'], $a['variants'], 5);
        $browser = pricingScreens([P::WHOLESALE_EDIT, P::CATEGORY_DISCOUNT_MANAGE]);

        $repeated = $browser->post('/admin/wholesale-prices', ['store' => 'sa', 'size' => $a['variants'][0], 'bands' => [['from' => '5', 'price' => '110.00'], ['from' => '5', 'price' => '100.00']]]);
        DB::table('catalog.categories')->where('id', $category)->update(['is_active' => false]);
        $off = $browser->get("/admin/category-discounts/left-out?store=sa&category={$category}");

        expect(pricingScreensErrors($repeated))->toHaveKey("rows.{$a['variants'][0]}")
            ->and(DB::table('pricing.wholesale_bands')->where('variant_id', $a['variants'][0])->exists())->toBeFalse()
            ->and($off->status())->toBeGreaterThanOrEqual(400);
    });

    it('previews a discount and opens its page within the budget with three other discounts running', function () {
        $categories = [Px::category('Kitchens'), Px::category('Baths'), Px::category('Doors'), Px::category('Floors')];
        $sizes = [];

        foreach ($categories as $n => $category) {
            $sizes[] = pricingScreensProduct("Product {$n}", ['60 cm'], $category)['variants'][0];
        }

        Rx::set('sa', array_fill_keys($sizes, '120.00'));
        $discount = pricingScreensDiscount($categories[0]);

        foreach (array_slice($categories, 1) as $category) {
            pricingScreensDiscount($category);
        }

        $browser = pricingScreens([P::CATEGORY_DISCOUNT_MANAGE]);
        $one = pricingScreensOwnQueries($browser, "/admin/category-discounts/preview?store=sa&category={$categories[0]}&percent=20&lines=both");
        $page = pricingScreensOwnQueries($browser, "/admin/category-discounts/{$discount}");

        // The other discounts' products in one read, however many (Catalog's productIdsInCategories).
        expect($one)->toBeLessThanOrEqual(15)
            ->and($page)->toBeLessThanOrEqual(15)
            ->and([$one, $page])->toBe([12, 15]);
    });
});

describe('the drawer\'s and the dialogs\' reads', function () {
    it('each reads its own queries within the budget', function () {
        $category = Px::category('Kitchens');
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm'], $category);
        Rx::set('sa', [$a['variants'][0] => '120.00', $a['variants'][1] => '130.00']);
        pricingScreensWholesale($a['product'], $a['variants'], 5, [5 => '100.00']);
        $sale = pricingScreensSale($a['variants']);
        pricingScreensDiscount($category);
        $browser = pricingScreens([P::PRICE_EDIT, P::WHOLESALE_EDIT, P::CATEGORY_DISCOUNT_MANAGE]);
        $reads = [
            'drawer' => '/admin/prices/size?store=sa&size='.$a['variants'][0],
            'note' => "/admin/offers/note?store=sa&product={$a['product']}&percent=20",
            'products' => '/admin/offers/products?store=sa&q=Alpha',
            'sizes' => "/admin/offers/sizes?store=sa&product={$a['product']}",
            'filter' => '/admin/offers/filter-products?store=sa',
            'offer' => "/admin/offers/{$sale}",
            'categories' => '/admin/category-discounts/categories?store=sa',
            'left out' => "/admin/category-discounts/left-out?store=sa&category={$category}",
            'preview' => "/admin/category-discounts/preview?store=sa&category={$category}&percent=20&lines=both",
            'wholesale sizes' => '/admin/wholesale-prices/sizes?store=sa',
        ];
        $counts = array_map(static fn (string $uri): int => pricingScreensOwnQueries($browser, $uri), $reads);

        // Recorded, each under the ceiling of 15 (§4.3.1 "Budget"): the drawer, the offer dialog's note, the
        // discount dialog's preview at it.
        expect($counts)->toBe([
            'drawer' => 13, 'note' => 15, 'products' => 3, 'sizes' => 4, 'filter' => 4, 'offer' => 12,
            'categories' => 2, 'left out' => 4, 'preview' => 13, 'wholesale sizes' => 3,
        ]);
    });
});

describe('the menu\'s Pricing group', function () {
    it('offers the five screens to whoever edits prices, Needs a Price with its count, and four to anyone else', function () {
        $a = pricingScreensProduct('Alpha', ['60 cm', '80 cm']);
        Rx::set('sa', [$a['variants'][0] => '120.00']);

        $editor = pricingScreensMenu(pricingScreens([P::PRICE_EDIT]));
        $wholesale = pricingScreensMenu(pricingScreens([P::WHOLESALE_EDIT]));
        $none = pricingScreensMenu(pricingScreens([CatalogPermissions::PRODUCT_VIEW]));

        expect(array_keys($editor))->toBe(['prices', 'offers', 'category_discounts', 'wholesale_prices', 'needs_a_price'])
            ->and($editor['needs_a_price'])->toBe(1)
            ->and(array_keys($wholesale))->toBe(['prices', 'offers', 'category_discounts', 'wholesale_prices'])
            ->and($none)->toBe([]);
    });
});

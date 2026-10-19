<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStock;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStockHandler;
use Modules\Inventory\Application\InventoryPermissions as P;
use Modules\Inventory\Application\Stock\LowStockCount;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Domain\ValueObject\StoreId;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Inventory's screens over HTTP (inventory.md §4.3, stage 5's screens step 5): Stock and its drawer, Low
| Stock, the dialogs' reads and every change - what each page offers, a store wired to a provider, an off store
| (a Super Admin's only), each page's own queries recorded under the admin budget of 15 (frontend.md §5), each
| change's toast - the amber one when orders are left short - and a refusal said on the screen; the guards;
| the menu's two entries with Low Stock's count; and the product page's section.
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

/** Signed in through the panel's door as this staff member, the panel in English. */
function inventoryScreensAs(string $staffId): AdminBrowser
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'en']);
    $browser = new AdminBrowser('10.9.6.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function inventoryScreens(array $permissions = [P::STOCK_MANAGE], array $stores = ['sa']): AdminBrowser
{
    return inventoryScreensAs(Fx::staffWith($permissions, $stores));
}

/**
 * A ready product named for the order, switched on in these stores.
 *
 * @param  list<string>  $sizes
 * @param  list<string>  $stores
 * @return array{product: string, variants: list<string>}
 */
function inventoryScreensProduct(string $nameEn, array $sizes = ['60 cm'], array $stores = ['sa']): array
{
    $ready = Px::ready($sizes);
    DB::table('catalog.products')->where('id', $ready['product'])->update(['name_en' => $nameEn]);

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId($store), $ready['product'], true)));
    }

    return ['product' => $ready['product'], 'variants' => $ready['variants']];
}

/** An order holding this many pieces of a size, placed as Sales places one. */
function inventoryScreensHold(string $size, int $quantity, string $store = 'sa'): void
{
    app(InventoryApi::class)->hold(StoreId::fromString(Ix::storeId($store)), strtolower((string) Str::ulid()), [new HoldLineDto($size, $quantity)], null);
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function inventoryScreensProps(TestResponse $response, string $component): array
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
 * @return array<string, mixed>
 */
function inventoryScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/**
 * How many queries the page asks for itself, opened a second time (warm): those made while Inventory's own
 * code runs - the panel's frame counted apart, Low Stock's count beside the menu entry with it (counted live
 * here, the queue being faked), and never the cache's rows.
 */
function inventoryScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        if (str_contains($query->sql, '"cache"')) {
            return;
        }

        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($frames as $frame) {
            if (($frame['class'] ?? '') === LowStockCount::class) {
                return;
            }
        }

        foreach ($frames as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Inventory/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

/**
 * The Catalog group's Inventory entries this person was offered, by key, with their counts.
 *
 * @return array<string, mixed>
 */
function inventoryScreensMenu(AdminBrowser $browser): array
{
    $found = [];

    $browser->get('/admin')->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$found): void {
        foreach ($inertia->toArray()['props']['menu'] ?? [] as $group) {
            foreach (is_array($group) ? ($group['entries'] ?? []) : [] as $entry) {
                if (in_array($entry['key'] ?? null, ['stock', 'low_stock'], true)) {
                    $found[$entry['key']] = $entry['count'] ?? null;
                }
            }
        }
    });

    return $found;
}

describe('Stock (I1)', function () {
    it('shows the store\'s sizes with their figures and one badge, and what the reader may do', function () {
        $a = inventoryScreensProduct('Alpha', ['60 cm', '80 cm']);
        Ix::count('sa', [$a['variants'][0] => 6, $a['variants'][1] => 2]);
        inventoryScreensHold($a['variants'][0], 5);
        Ix::count('sa', [$a['variants'][0] => 3]);

        $page = inventoryScreensProps(inventoryScreens([P::STOCK_MANAGE, CatalogPermissions::PRODUCT_VIEW])->get('/admin/stock'), 'Inventory/Admin/Stock');
        inventoryScreens([CatalogPermissions::PRODUCT_VIEW])->get('/admin/stock')->assertForbidden();
        $rows = array_column(array_column($page['rows'], 'figures'), null);

        expect($page['total'])->toBe(2)
            ->and($page['rows'][0]['size']['variantId'])->toBe($a['variants'][0])
            ->and([$rows[0]['inStock'], $rows[0]['held'], $rows[0]['badge'], $rows[0]['shortBy']])->toBe([3, 5, 'SHORT', 2])
            ->and($rows[1]['badge'])->toBe('LOW')
            ->and($page['rows'][0]['lastPieces'])->toBeFalse()
            ->and($page['storeFrame']['mayViewProducts'])->toBeTrue()
            ->and($page['storeFrame']['wired'])->toBeFalse()
            ->and($page['storeFrame']['defaultThreshold'])->toBe(10)
            ->and($page['drawer'])->toBeNull()
            ->and($page['translations']['inventory::admin_stock.title'])->toBe('Stock');
    });

    it('filters, searches, and opens a size\'s drawer with its holds and history in the address', function () {
        $a = inventoryScreensProduct('Alpha', ['60 cm', '80 cm']);
        Ix::count('sa', [$a['variants'][0] => 6, $a['variants'][1] => 40]);
        inventoryScreensHold($a['variants'][0], 2);
        $browser = inventoryScreens();

        $low = inventoryScreensProps($browser->get('/admin/stock?filter=low'), 'Inventory/Admin/Stock');
        $drawer = inventoryScreensProps($browser->get('/admin/stock?size='.$a['variants'][0]), 'Inventory/Admin/Stock');
        $size = $browser->get('/admin/stock/size?store=sa&size='.$a['variants'][0])->assertOk()->json();
        $history = $browser->get('/admin/stock/history?store=sa&size='.$a['variants'][0])->assertOk()->json();

        expect(array_column(array_column($low['rows'], 'size'), 'variantId'))->toBe([$a['variants'][0]])
            ->and($low['filter'])->toBe('LOW')
            ->and($drawer['drawer']['size']['variantId'])->toBe($a['variants'][0])
            ->and($drawer['drawer']['holds']['orders'])->toBe(1)
            ->and($drawer['history']['lines'][0]['reason'])->toBe('STOCKTAKE')
            ->and($size['figures']['held'])->toBe(2)
            ->and($history['lines'])->toHaveCount(1);
    });

    it('offers only the stores where the reader manages stock, refuses another, and shows an off store to a Super Admin only, marked', function () {
        inventoryScreensProduct('Alpha');
        $browser = inventoryScreens([P::STOCK_MANAGE], ['sa', 'eg']);

        $page = inventoryScreensProps($browser->get('/admin/stock'), 'Inventory/Admin/Stock');
        $browser->get('/admin/stock?store=ae')->assertForbidden();
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $staff = inventoryScreensProps($browser->get('/admin/stock'), 'Inventory/Admin/Stock');
        $off = inventoryScreensProps(inventoryScreensAs(Fx::staff(superAdmin: true))->get('/admin/stock?store=eg'), 'Inventory/Admin/Stock');

        expect(array_column($page['storeFrame']['stores'], 'code'))->toBe(['sa', 'eg'])
            ->and(array_column($staff['storeFrame']['stores'], 'code'))->toBe(['sa'])
            ->and($off['storeFrame']['off'])->toBeTrue()
            ->and(array_column($off['storeFrame']['stores'], 'isActive', 'code')['eg'] ?? null)->toBeFalse();

        $browser->get('/admin/stock?store=eg')->assertForbidden();
    });

    it('says a wired store\'s stock is the provider\'s, and offers no Last Pieces there', function () {
        $a = inventoryScreensProduct('Alpha');
        Ix::wired();

        $page = inventoryScreensProps(inventoryScreens()->get('/admin/stock'), 'Inventory/Admin/Stock');

        expect($page['storeFrame']['wired'])->toBeTrue()
            ->and($page['storeFrame']['reasons']['wired'])->toBe("The provider sets this store's stock.")
            ->and($page['rows'][0]['size']['variantId'])->toBe($a['variants'][0])
            ->and($page['rows'][0]['lastPieces'])->toBeNull();
    });

    it('saves counts with their toast, the amber one when orders are left short, and marks a refused row', function () {
        $a = inventoryScreensProduct('Alpha', ['60 cm', '80 cm']);
        [$sixty, $eighty] = $a['variants'];
        Ix::count('sa', [$sixty => 6]);
        inventoryScreensHold($sixty, 5);
        $browser = inventoryScreens();

        $saved = $browser->post('/admin/stock/counts', ['store' => 'sa', 'counts' => [$sixty => '2', $eighty => '9']]);
        $refused = $browser->post('/admin/stock/counts', ['store' => 'sa', 'counts' => [$eighty => 'abc']]);

        expect(AdminBrowser::flashed($saved, 'status'))->toBe('Counts saved')
            ->and(AdminBrowser::flashed($saved, 'warning'))->toBe('1 order is short of 60 cm')
            ->and(Ix::stock('sa', $sixty)?->in_stock)->toBe(2)
            ->and(inventoryScreensErrors($refused))->toHaveKey("rows.{$eighty}")
            ->and(Ix::stock('sa', $eighty)?->in_stock)->toBe(9);
    });

    it('adds and removes stock with their toasts, and refuses a correction with no note', function () {
        $a = inventoryScreensProduct('Alpha');
        [$size] = $a['variants'];
        Ix::count('sa', [$size => 6]);
        $browser = inventoryScreens();

        $added = $browser->post('/admin/stock/adjust', ['store' => 'sa', 'size' => $size, 'direction' => 'ADD', 'quantity' => '4', 'reason' => 'RECEIVED']);
        $removed = $browser->post('/admin/stock/adjust', ['store' => 'sa', 'size' => $size, 'direction' => 'REMOVE', 'quantity' => '3', 'reason' => 'DAMAGED_LOST']);
        $refused = $browser->post('/admin/stock/adjust', ['store' => 'sa', 'size' => $size, 'direction' => 'ADD', 'quantity' => '1', 'reason' => 'CORRECTION']);

        expect(AdminBrowser::flashed($added, 'status'))->toBe('Stock added')
            ->and(AdminBrowser::flashed($removed, 'status'))->toBe('Stock removed')
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(7)
            ->and(AdminBrowser::formError($refused))->not->toBeEmpty()
            ->and(AdminBrowser::flashed($refused, 'status'))->toBeNull();
    });

    it('sets thresholds for one size and for several, the default with nothing typed, and refuses one out of bounds', function () {
        $a = inventoryScreensProduct('Alpha', ['60 cm', '80 cm']);
        $browser = inventoryScreens();

        $one = $browser->post('/admin/stock/thresholds', ['store' => 'sa', 'sizes' => [$a['variants'][0]], 'threshold' => '4']);
        $many = $browser->post('/admin/stock/thresholds', ['store' => 'sa', 'sizes' => $a['variants'], 'threshold' => '']);
        $refused = $browser->post('/admin/stock/thresholds', ['store' => 'sa', 'sizes' => [$a['variants'][1]], 'threshold' => '200000']);

        expect(AdminBrowser::flashed($one, 'status'))->toBe('Threshold saved')
            ->and(AdminBrowser::flashed($many, 'status'))->toBe('Thresholds saved')
            ->and(Ix::stock('sa', $a['variants'][0])?->threshold)->toBeNull()
            ->and(inventoryScreensErrors($refused))->toHaveKey("rows.{$a['variants'][1]}");
    });

    it('shows and hides Last Pieces, and switches Counts on Stock in a wired store only', function () {
        $a = inventoryScreensProduct('Alpha');
        $browser = inventoryScreens();

        $shown = $browser->post('/admin/stock/last-pieces', ['store' => 'sa', 'product' => $a['product'], 'on' => true]);
        $hidden = $browser->post('/admin/stock/last-pieces', ['store' => 'sa', 'product' => $a['product'], 'on' => false]);
        $notWired = $browser->post('/admin/stock/counts-on-stock', ['store' => 'sa', 'sizes' => $a['variants'], 'on' => true]);
        Ix::wired();
        $on = $browser->post('/admin/stock/counts-on-stock', ['store' => 'sa', 'sizes' => $a['variants'], 'on' => true]);
        $off = $browser->post('/admin/stock/counts-on-stock', ['store' => 'sa', 'sizes' => $a['variants'], 'on' => false]);

        expect(AdminBrowser::flashed($shown, 'status'))->toBe('Last pieces shown')
            ->and(AdminBrowser::flashed($hidden, 'status'))->toBe('Last pieces hidden')
            ->and(AdminBrowser::formError($notWired))->not->toBeEmpty()
            ->and(AdminBrowser::flashed($on, 'status'))->toBe('Stock dependency turned on')
            ->and(AdminBrowser::flashed($off, 'status'))->toBe('Stock dependency turned off');
    });

    it('finds a size to add, and lists it once counted', function () {
        $gift = inventoryScreensProduct('Gift Box', stores: []);
        $browser = inventoryScreens();

        $choices = $browser->get('/admin/stock/add-sizes?store=sa&q=Gift')->assertOk()->json();
        $saved = $browser->post('/admin/stock/counts', ['store' => 'sa', 'counts' => [$gift['variants'][0] => '5']]);
        $page = inventoryScreensProps($browser->get('/admin/stock?q=Gift'), 'Inventory/Admin/Stock');

        expect(array_column($choices['rows'], 'variantId'))->toBe([$gift['variants'][0]])
            ->and(AdminBrowser::flashed($saved, 'status'))->toBe('Counts saved')
            ->and(array_column(array_column($page['rows'], 'size'), 'variantId'))->toBe([$gift['variants'][0]]);
    });
});

describe('the guards', function () {
    it('refuses the dialogs\' reads and every change in a store outside the reader\'s, and a change naming no store', function () {
        $a = inventoryScreensProduct('Alpha', stores: ['sa', 'eg']);
        [$size] = $a['variants'];
        Ix::count('eg', [$size => 4]);
        $browser = inventoryScreens([P::STOCK_MANAGE], ['sa']);

        foreach (["/admin/stock/size?store=eg&size={$size}", "/admin/stock/history?store=eg&size={$size}", '/admin/stock/add-sizes?store=eg', '/admin/low-stock?store=eg'] as $uri) {
            $browser->get($uri)->assertForbidden();
        }

        foreach (['eg', null] as $store) {
            foreach ([
                '/admin/stock/counts' => ['counts' => [$size => '1']],
                '/admin/stock/adjust' => ['size' => $size, 'direction' => 'ADD', 'quantity' => '1', 'reason' => 'RECEIVED'],
                '/admin/stock/thresholds' => ['sizes' => [$size], 'threshold' => '3'],
                '/admin/stock/last-pieces' => ['product' => $a['product'], 'on' => true],
                '/admin/stock/counts-on-stock' => ['sizes' => [$size], 'on' => true],
            ] as $uri => $data) {
                $response = $browser->post($uri, $store === null ? $data : ['store' => $store, ...$data]);

                expect(AdminBrowser::formError($response))->not->toBeEmpty("{$uri} in ".($store ?? 'no store').' was not refused')
                    ->and(AdminBrowser::flashed($response, 'status'))->toBeNull();
            }
        }

        expect(Ix::stock('eg', $size)?->in_stock)->toBe(4);
    });
});

describe('Low Stock (I2)', function () {
    it('lists the low sizes, the fewest left first, and says how many in which store', function () {
        $a = inventoryScreensProduct('Alpha', ['60 cm', '80 cm', '100 cm']);
        Ix::count('sa', [$a['variants'][0] => 4, $a['variants'][1] => 1, $a['variants'][2] => 50]);

        $page = inventoryScreensProps(inventoryScreens()->get('/admin/low-stock'), 'Inventory/Admin/LowStock');

        expect(array_column(array_column($page['rows'], 'size'), 'variantId'))->toBe([$a['variants'][1], $a['variants'][0]])
            ->and($page['total'])->toBe(2)
            ->and($page['storeFrame']['storeName'])->not->toBe('')
            ->and($page['translations']['inventory::admin_low.title'])->toBe('Low Stock');
    });
});

describe('the budget', function () {
    it('reads each page\'s and each read\'s own queries within 15, recorded - a wired store\'s too', function () {
        $a = inventoryScreensProduct('Alpha', ['60 cm', '80 cm', '100 cm']);
        Ix::count('sa', [$a['variants'][0] => 4, $a['variants'][1] => 1, $a['variants'][2] => 50]);
        inventoryScreensHold($a['variants'][0], 2);
        $browser = inventoryScreens([P::STOCK_MANAGE, CatalogPermissions::PRODUCT_VIEW]);
        $reads = [
            'stock' => '/admin/stock',
            'stock with a drawer' => '/admin/stock?size='.$a['variants'][0],
            'low stock' => '/admin/low-stock',
            'size' => '/admin/stock/size?store=sa&size='.$a['variants'][0],
            'history' => '/admin/stock/history?store=sa&size='.$a['variants'][0],
            'add sizes' => '/admin/stock/add-sizes?store=sa&q=Alpha',
        ];
        $counts = array_map(static fn (string $uri): int => inventoryScreensOwnQueries($browser, $uri), $reads);
        Ix::wired();
        $wired = [
            'stock' => inventoryScreensOwnQueries($browser, '/admin/stock'),
            'stock with a drawer' => inventoryScreensOwnQueries($browser, $reads['stock with a drawer']),
            'low stock' => inventoryScreensOwnQueries($browser, '/admin/low-stock'),
        ];

        expect(array_filter([...array_values($counts), ...array_values($wired)], static fn (int $count): bool => $count > 15))->toBe([])
            ->and($counts)->toBe(['stock' => 9, 'stock with a drawer' => 15, 'low stock' => 8, 'size' => 6, 'history' => 1, 'add sizes' => 8])
            ->and($wired)->toBe(['stock' => 7, 'stock with a drawer' => 12, 'low stock' => 7]);
    });
});

describe('the menu and the product page', function () {
    it('offers Stock and Low Stock, with the low sizes counted, to whoever manages stock and no one else', function () {
        $a = inventoryScreensProduct('Alpha', ['60 cm', '80 cm']);
        Ix::count('sa', [$a['variants'][0] => 1, $a['variants'][1] => 50]);
        Fx::asSystem(fn () => app(CountLowStockHandler::class)->handle(new CountLowStock));

        $manager = inventoryScreensMenu(inventoryScreens());
        $other = inventoryScreensMenu(inventoryScreens([CatalogPermissions::PRODUCT_VIEW]));

        expect(array_keys($manager))->toBe(['stock', 'low_stock'])
            ->and($manager['low_stock'])->toBe(1)
            ->and($other)->toBe([]);
    });

    it('draws Inventory\'s part of each card the reader manages stock in, its words and the store\'s default with it', function () {
        $a = inventoryScreensProduct('Alpha', ['60 cm'], ['sa', 'eg']);
        Ix::count('sa', [$a['variants'][0] => 7]);
        $browser = inventoryScreens([CatalogPermissions::PRODUCT_VIEW, P::STOCK_MANAGE], ['sa', 'eg']);
        $cards = [];

        $browser->get("/admin/products/{$a['product']}?tab=stores")->assertOk()->assertInertia(function (AssertableInertia $page) use (&$cards): void {
            foreach ($page->toArray()['props']['stores'] ?? [] as $store) {
                foreach ($store['sections'] as $section) {
                    $cards[$store['code']] = $section['module'] === 'inventory' ? $section : ($cards[$store['code']] ?? null);
                }
            }
        });

        expect($cards['sa']['component'] ?? null)->toBe('Inventory/ProductStock')
            ->and($cards['sa']['data']['sizes'][0]['figures']['inStock'])->toBe(7)
            ->and($cards['sa']['data']['defaultThreshold'])->toBe(10)
            ->and($cards['eg']['data']['sizes'][0]['figures']['inStock'])->toBe(0)
            ->and($cards['sa']['data']['words']['inventory::admin_stock.section.title'])->toBe('Stock');
    });
});

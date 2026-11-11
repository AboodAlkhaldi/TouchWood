<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Products\InMemoryProductStoreSections;
use Modules\Inventory\Application\Command\AdjustStock\AdjustStock;
use Modules\Inventory\Application\Command\AdjustStock\AdjustStockHandler;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoon;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoonHandler;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThreshold;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThresholdHandler;
use Modules\Inventory\Application\Command\SetStock\SetStock;
use Modules\Inventory\Application\Command\SetStock\SetStockHandler;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependent;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependentHandler;
use Modules\Inventory\Application\Command\SetStockFromFile\SetStockFromFile;
use Modules\Inventory\Application\Command\SetStockFromFile\SetStockFromFileHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Query\AddSizeChoices\AddSizeChoices;
use Modules\Inventory\Application\Query\AddSizeChoices\AddSizeChoicesHandler;
use Modules\Inventory\Application\Query\HoldsOnSizes\HoldsOnSizes;
use Modules\Inventory\Application\Query\HoldsOnSizes\HoldsOnSizesHandler;
use Modules\Inventory\Application\Query\InventoryActions\InventoryActionsForReader;
use Modules\Inventory\Application\Query\InventoryActions\StoreInventoryActions;
use Modules\Inventory\Application\Query\ListStock\ListStock;
use Modules\Inventory\Application\Query\ListStock\ListStockHandler;
use Modules\Inventory\Application\Query\ListStock\StockList;
use Modules\Inventory\Application\Query\ListStock\StockListRow;
use Modules\Inventory\Application\Query\LowStock\LowStock;
use Modules\Inventory\Application\Query\LowStock\LowStockHandler;
use Modules\Inventory\Application\Query\LowStock\LowStockList;
use Modules\Inventory\Application\Query\LowStock\LowStockRow;
use Modules\Inventory\Application\Query\StockHistory\HistoryLine;
use Modules\Inventory\Application\Query\StockHistory\StockHistory;
use Modules\Inventory\Application\Query\StockHistory\StockHistoryHandler;
use Modules\Inventory\Application\Query\StockViews\SizeView;
use Modules\Inventory\Application\Query\ViewProductStock\ProductSizeStock;
use Modules\Inventory\Application\Query\ViewProductStock\ProductStoreStock;
use Modules\Inventory\Application\Query\ViewProductStock\ViewProductStock;
use Modules\Inventory\Application\Query\ViewProductStock\ViewProductStockHandler;
use Modules\Inventory\Application\Query\ViewStock\ViewStock;
use Modules\Inventory\Application\Query\ViewStock\ViewStockHandler;
use Modules\Inventory\Application\Stock\ShortSize;
use Modules\Inventory\Application\Stock\StockSaved;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\ProviderOwnsStock;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Exception\StockBelowZero;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Exception\ThresholdInvalid;
use Modules\Inventory\Infrastructure\Catalog\ProductStockSection;
use Modules\Inventory\Infrastructure\Queue\SetStockFromFileJob;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Inventory\Public\Events\HoldsShort;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Inventory's reads for stage 5's screens (inventory.md §4.3.3; screens step 4): the Stock list and its
| filters, a size's drawer and history, a product's stock in every store, the Low Stock list paged in its
| order, what the screens may offer, the holds said before saving, Add Size's picker - each a fixed few
| queries however many rows - and the commands' part: refusals naming their sizes (Q2), and the sizes a
| save left short (the amber toast).
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-14 10:00:00+00');
    app()->setLocale('en');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A ready product named for the order, switched on in these stores.
 *
 * @param  list<string>  $sizes
 * @param  list<string>  $stores
 * @return array{product: string, variants: list<string>}
 */
function stockProduct(string $nameEn, array $sizes = ['60 cm'], array $stores = ['sa']): array
{
    $ready = Px::ready($sizes);
    DB::table('catalog.products')->where('id', $ready['product'])->update(['name_en' => $nameEn]);

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId($store), $ready['product'], true)));
    }

    return ['product' => $ready['product'], 'variants' => $ready['variants']];
}

/**
 * An order holding these lines in the store, placed as Sales places one.
 *
 * @param  list<HoldLineDto>  $lines
 */
function stockHold(array $lines, string $store = 'sa'): string
{
    $order = strtolower((string) Str::ulid());
    app(InventoryApi::class)->hold(StoreId::fromString(Ix::storeId($store)), $order, $lines, null);

    return $order;
}

/**
 * How many queries a read asks, once warm - never the cache's.
 */
function stockQueries(callable $read): int
{
    $read();
    $queries = Cx::recordQueries();
    $read();

    return count($queries);
}

/**
 * The error this work was refused with.
 */
function stockRefusal(callable $work): DomainError
{
    try {
        $work();
    } catch (DomainError $error) {
        return $error;
    }

    throw new LogicException('Not refused.');
}

/**
 * @return list<string>
 */
function stockIds(StockList $list): array
{
    return array_map(static fn (StockListRow $row): string => $row->size->variantId, $list->rows);
}

function stockList(?string $search = null, string $filter = ListStock::ALL, ?string $after = null, string $store = 'sa'): StockList
{
    return app(ListStockHandler::class)->handle(new ListStock(Ix::storeId($store), $search, $filter, $after));
}

/**
 * A staff member managing stock in these stores, acting from now on.
 *
 * @param  list<string>  $stores
 */
function stockManager(array $stores = ['sa']): string
{
    return Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], $stores);
}

describe('the Stock list (I1)', function () {
    it('lists the sizes switched on and those stocked though switched off, by product name then size, each with its figures and one badge', function () {
        $b = stockProduct('Bravo', ['60 cm', '80 cm']);
        $a = stockProduct('Alpha');
        $gift = stockProduct('Charlie', stores: []);
        Ix::count('sa', [$b['variants'][0] => 6, $b['variants'][1] => 50, $gift['variants'][0] => 20]);
        Fx::asSystem(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold(Ix::storeId('sa'), [$b['variants'][1] => 60])));
        stockHold([new HoldLineDto($b['variants'][0], 5)]);
        // Counted down below what the order holds: short by 2.
        Ix::count('sa', [$b['variants'][0] => 3]);
        stockManager();

        $list = stockList();
        $rows = [];

        foreach ($list->rows as $row) {
            $rows[$row->size->variantId] = $row;
        }

        $badge = static fn (string $id): array => [$rows[$id]->figures->inStock, $rows[$id]->figures->held, $rows[$id]->figures->available, $rows[$id]->figures->badge, $rows[$id]->figures->shortBy];

        expect(stockIds($list))->toBe([$a['variants'][0], $b['variants'][0], $b['variants'][1], $gift['variants'][0]])
            ->and([$list->total, $list->next])->toBe([4, null])
            // No stock row: 0, and out of stock - a store with no provider counts every size on its stock.
            ->and($badge($a['variants'][0]))->toBe([0, 0, 0, 'OUT_OF_STOCK', 0])
            ->and($badge($b['variants'][0]))->toBe([3, 5, 0, 'SHORT', 2])
            ->and($rows[$b['variants'][0]]->figures->holdingOrders)->toBe(1)
            ->and($badge($b['variants'][1]))->toBe([50, 0, 50, 'LOW', 0])
            ->and([$rows[$b['variants'][1]]->figures->threshold, $rows[$b['variants'][1]]->figures->ownThreshold])->toBe([60, 60])
            ->and([$rows[$a['variants'][0]]->figures->threshold, $rows[$a['variants'][0]]->figures->ownThreshold])->toBe([10, null])
            ->and($badge($gift['variants'][0]))->toBe([20, 0, 20, null, 0])
            // Stocked though switched off: listed, marked.
            ->and([$rows[$gift['variants'][0]]->size->switchedOn, $rows[$b['variants'][0]]->size->switchedOn])->toBe([false, true])
            ->and([$rows[$b['variants'][0]]->size->values, $rows[$b['variants'][0]]->size->productNameEn, $rows[$b['variants'][0]]->size->archived])->toBe([['60 cm'], 'Bravo', false])
            ->and($rows[$b['variants'][0]]->size->photoMediaId)->not->toBeNull()
            ->and($rows[$b['variants'][0]]->figures->providerChangedAt)->toBeNull();
    });

    it('keeps its filters - Low, Out of Stock, Held, Short - and its search, an archived size left off Low only', function () {
        $plenty = stockProduct('Plenty');
        $none = stockProduct('Nothing');
        $held = stockProduct('Held', ['60 cm', '80 cm']);
        $archived = stockProduct('Archived', ['60 cm', '80 cm']);
        Ix::count('sa', [$plenty['variants'][0] => 40, $held['variants'][0] => 30, $held['variants'][1] => 4, $archived['variants'][0] => 1, $archived['variants'][1] => 40]);
        stockHold([new HoldLineDto($held['variants'][0], 2)]);
        stockHold([new HoldLineDto($held['variants'][1], 4)]);
        Ix::count('sa', [$held['variants'][1] => 1]);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($archived['variants'][0])));
        stockManager();

        expect(stockIds(stockList(filter: ListStock::LOW)))->toBe([$held['variants'][1], $none['variants'][0]])
            ->and(stockIds(stockList(filter: ListStock::OUT_OF_STOCK)))->toBe([$held['variants'][1], $none['variants'][0]])
            ->and(stockIds(stockList(filter: ListStock::HELD)))->toBe([$held['variants'][0], $held['variants'][1]])
            ->and(stockIds(stockList(filter: ListStock::SHORT)))->toBe([$held['variants'][1]])
            ->and(stockIds(stockList('plen')))->toBe([$plenty['variants'][0]])
            // Listed while it has stock, marked: read-only until restored.
            ->and(stockList('archived')->rows[0]->size->archived)->toBeTrue()
            ->and(Ix::invalid(fn () => stockList(filter: 'CHEAP'))?->attribute)->toBe('filter');

        stockManager(['eg']);

        expect(fn () => stockList())->toThrow(Unauthorized::class);
    });

    it('pages 50 at a time after the cursor, and asks the same few queries for 1 row as for many', function () {
        $first = stockProduct('Aa', ['1']);
        Ix::count('sa', [$first['variants'][0] => 3]);
        stockManager();
        $read = static fn (): StockList => stockList();
        $one = stockQueries($read);

        $many = stockProduct('Bb', array_map('strval', range(2, 55)));
        Ix::count('sa', array_fill_keys(array_slice($many['variants'], 0, 20), 5));
        stockHold([new HoldLineDto($many['variants'][0], 2), new HoldLineDto($many['variants'][1], 1, gift: true)]);
        $page = $read();
        $next = stockList(after: $page->next);

        expect([count($page->rows), $page->total, count($next->rows)])->toBe([50, 55, 5])
            ->and($next->next)->toBeNull()
            ->and(array_intersect(stockIds($page), stockIds($next)))->toBe([])
            ->and(stockQueries($read))->toBe($one)
            // 7, and since screens step 5 one more: the rows' products' Last Pieces, for each row's menu.
            ->and($one)->toBeLessThanOrEqual(8);
    });
});

describe('a wired store', function () {
    it('shows the provider\'s number and when it last changed it, Orderable for an ordinary size at 0, Out of Stock for one that counts on stock', function () {
        $ready = stockProduct('Wired', ['60 cm', '80 cm', '100 cm']);
        [$ordinary, $dependent, $sent] = $ready['variants'];
        Ix::wired();
        $sa = StoreId::fromString(Ix::storeId('sa'));
        // On by default (§9.2 #24): the ordinary sizes are those staff switched off.
        Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent($sa->value, [$ordinary, $sent], false)));
        Fx::asSystem(fn () => app(ProviderStock::class)->apply($sa, [$dependent => 5, $sent => 30], 'read-1'));
        Fx::asSystem(fn () => app(ProviderStock::class)->apply($sa, [$dependent => 0, $sent => 30], 'read-2'));
        stockManager();

        $rows = [];

        foreach (stockList()->rows as $row) {
            $rows[$row->size->variantId] = $row->figures;
        }

        $lastChange = static fn (string $id): string => CarbonImmutable::parse((string) DB::table('inventory.stock_movements')->where('variant_id', $id)->where('reason', 'PROVIDER')->orderByDesc('id')->value('created_at'))->format(DateTimeInterface::ATOM);

        expect([$rows[$ordinary]->badge, $rows[$ordinary]->countsOnStock, $rows[$ordinary]->providerChangedAt])->toBe(['ORDERABLE', false, null])
            ->and([$rows[$dependent]->badge, $rows[$dependent]->countsOnStock, $rows[$dependent]->stockDependent])->toBe(['OUT_OF_STOCK', true, true])
            ->and($rows[$dependent]->providerChangedAt)->toBe($lastChange($dependent))
            // The same number sent again writes no line: the time is still the first one's.
            ->and(DB::table('inventory.stock_movements')->where('variant_id', $sent)->count())->toBe(1)
            ->and([$rows[$sent]->inStock, $rows[$sent]->badge, $rows[$sent]->stockDependent])->toBe([30, null, false])
            ->and($rows[$sent]->providerChangedAt)->toBe($lastChange($sent));
    });
});

describe('a size\'s drawer (ViewStock)', function () {
    it('says its figures, badge and threshold, the product\'s Last Pieces, and what orders hold, bought and gift apart', function () {
        $ready = stockProduct('Drawer', ['60 cm', '80 cm']);
        [$size] = $ready['variants'];
        Ix::count('sa', [$size => 10]);
        stockHold([new HoldLineDto($size, 3), new HoldLineDto($size, 2, gift: true)]);
        stockHold([new HoldLineDto($size, 1)]);
        Fx::asSystem(fn () => app(SetEndingSoonHandler::class)->handle(new SetEndingSoon(Ix::storeId('sa'), $ready['product'], true)));
        stockManager();
        $read = static fn () => app(ViewStockHandler::class)->handle(new ViewStock(Ix::storeId('sa'), $size));

        $view = $read();

        expect([$view->figures->inStock, $view->figures->held, $view->figures->available, $view->figures->badge])->toBe([10, 6, 4, 'LOW'])
            ->and([$view->figures->threshold, $view->figures->ownThreshold])->toBe([10, null])
            ->and($view->lastPieces)->toBeTrue()
            ->and([$view->holds->orders, $view->holds->pieces, $view->holds->bought, $view->holds->gift])->toBe([2, 6, 4, 2])
            ->and([$view->size->variantId, $view->size->values])->toBe([$size, ['60 cm']])
            ->and(stockQueries($read))->toBeLessThanOrEqual(6);

        $unknown = Ix::invalid(fn () => app(ViewStockHandler::class)->handle(new ViewStock(Ix::storeId('sa'), strtolower((string) Str::ulid()))));

        expect($unknown?->attribute)->toBe('variant')
            ->and($unknown?->variants)->toHaveCount(1);
    });
});

describe('a size\'s history (StockHistory)', function () {
    it('lists its ledger newest first, saying who - a person by name, a store file and who switched it on, an order, the system', function () {
        [$size] = stockProduct('History')['variants'];
        $huda = Fx::staff(firstName: 'Huda');
        Fx::assign($huda, Fx::role([InventoryPermissions::STOCK_MANAGE]), ['sa']);
        Fx::actAsStaff($huda);
        app(SetStockHandler::class)->handle(new SetStock(Ix::storeId('sa'), [$size => 10]));
        app(AdjustStockHandler::class)->handle(new AdjustStock(Ix::storeId('sa'), $size, 'REMOVE', 2, 'DAMAGED_LOST'));
        app(AdjustStockHandler::class)->handle(new AdjustStock(Ix::storeId('sa'), $size, 'ADD', 1, 'CORRECTION', 'Found one'));
        $order = stockHold([new HoldLineDto($size, 3)]);
        app(InventoryApi::class)->ship($order, [new HoldLineDto($size, 1)]);
        $admin = Fx::staffWith([CatalogPermissions::LISTING_FILL], ['sa'], RoleLevel::Admin);
        Fx::actAsStaff($admin);
        // Queued by the admin, run by the queue as the system on their behalf.
        SetStockFromFileJob::dispatch(Ix::storeId('sa'), $size, 20, 'file:history');
        Ix::count('sa', [$size => 21]);
        Fx::actAsStaff($huda);

        $page = app(StockHistoryHandler::class)->handle(new StockHistory(Ix::storeId('sa'), $size));
        $line = static fn (HistoryLine $line): string => "{$line->reason} {$line->before}→{$line->after} {$line->change} {$line->who}";

        expect(array_map($line, $page->lines))->toBe([
            'STOCKTAKE 20→21 1 SYSTEM',
            'FILE 8→20 12 FILE',
            'SHIPPED 9→8 -1 ORDER',
            'CORRECTION 8→9 1 STAFF',
            'DAMAGED_LOST 10→8 -2 STAFF',
            'STOCKTAKE 0→10 10 STAFF',
        ])
            ->and($page->next)->toBeNull()
            ->and($page->lines[2]->orderId)->toBe($order)
            ->and($page->lines[3]->note)->toBe('Found one')
            ->and($page->lines[3]->staffName)->toContain('Huda')
            ->and($page->lines[3]->staffHidden)->toBeFalse()
            // The file's line names whoever switched it on; the system's names no one.
            ->and($page->lines[1]->staffName)->not->toBeNull()
            ->and($page->lines[1]->staffName)->not->toContain('Huda')
            ->and([$page->lines[0]->staffName, $page->lines[2]->staffName])->toBe([null, null])
            ->and($page->lines[0]->at)->toMatch('/\A\d{4}-\d{2}-\d{2}T/');
    });

    it('shows a shipment from a stock already at 0 as a 0 → 0 line with its shortfall, by its order, in the page\'s language (owner, 2026-10-19)', function (string $locale, string $note) {
        [$size] = stockProduct('Shortfall')['variants'];
        Ix::count('sa', [$size => 2]);
        $order = stockHold([new HoldLineDto($size, 2)]);
        Ix::adjust('sa', $size, 'REMOVE', 2, 'DAMAGED_LOST');
        app(InventoryApi::class)->ship($order, [new HoldLineDto($size, 2)]);
        stockManager();
        app()->setLocale($locale);

        $first = app(StockHistoryHandler::class)->handle(new StockHistory(Ix::storeId('sa'), $size))->lines[0];

        expect("{$first->reason} {$first->before}→{$first->after} {$first->change} {$first->who}")->toBe('SHIPPED 0→0 0 ORDER')
            ->and($first->note)->toBe($note)
            ->and($first->orderId)->toBe($order);
    })->with([
        'English' => ['en', 'Shipped 2 more than counted. Recount.'],
        'Arabic' => ['ar', 'شُحن 2 أكثر من المعدود. أعد الجرد.'],
    ]);

    it('shows a note typed by staff, and a line\'s note written before the codes, as they are, and the unwiring\'s own in the page\'s language (stage 5 step 10)', function (string $locale, string $unwired) {
        [$size] = stockProduct('Old Notes')['variants'];
        Ix::count('sa', [$size => 3]);
        Ix::adjust('sa', $size, 'REMOVE', 1, 'CORRECTION', 'Found one broken');
        // A line the ledger kept from before the codes: its English text, never turned into one.
        DB::table('inventory.stock_movements')->insert([
            'store_id' => Ix::storeId('sa'), 'variant_id' => $size, 'reason' => 'CORRECTION', 'change' => 2, 'before' => 2, 'after' => 4,
            'note' => 'unwired', 'reference' => 'unwired:old:'.$size, 'actor_type' => 'SYSTEM', 'actor_id' => null,
        ]);
        DB::table('inventory.stock_movements')->insert([
            'store_id' => Ix::storeId('sa'), 'variant_id' => $size, 'reason' => 'CORRECTION', 'change' => 1, 'before' => 4, 'after' => 5,
            'note_code' => 'UNWIRED', 'reference' => 'unwired:new:'.$size, 'actor_type' => 'SYSTEM', 'actor_id' => null,
        ]);
        stockManager();
        app()->setLocale($locale);

        $notes = array_map(static fn ($line): ?string => $line->note, app(StockHistoryHandler::class)->handle(new StockHistory(Ix::storeId('sa'), $size))->lines);

        expect($notes)->toBe([$unwired, 'unwired', 'Found one broken', null]);
    })->with([
        'English' => ['en', 'The store was unwired: the pieces returned while it was wired are added.'],
        'Arabic' => ['ar', 'فُكّ ربط المتجر: أُضيفت القطع المرتجعة أثناء ربطه.'],
    ]);

    it('pages 50 at a time, newest first, in the same few queries', function () {
        [$size] = stockProduct('Long')['variants'];
        stockManager();
        $read = static fn (?string $after = null) => app(StockHistoryHandler::class)->handle(new StockHistory(Ix::storeId('sa'), $size, $after));
        Ix::count('sa', [$size => 1]);
        $one = stockQueries($read);

        foreach (range(2, 55) as $count) {
            Ix::count('sa', [$size => $count]);
        }

        $page = $read();
        $next = $read($page->next);

        expect([count($page->lines), count($next->lines), $next->next])->toBe([50, 5, null])
            ->and([$page->lines[0]->after, $next->lines[4]->after])->toBe([55, 1])
            ->and(stockQueries($read))->toBe($one)
            ->and($one)->toBeLessThanOrEqual(2);
    });
});

describe('a product\'s stock in every store (the product page)', function () {
    it('answers each store the reader manages stock in, in one read for every card, and registers itself with Catalog', function () {
        $ready = stockProduct('Drawer', ['60 cm', '80 cm'], ['sa', 'eg']);
        [$a, $b] = $ready['variants'];
        Ix::count('sa', [$a => 4]);
        Ix::count('eg', [$a => 7, $b => 30]);
        Fx::asSystem(fn () => app(SetEndingSoonHandler::class)->handle(new SetEndingSoon(Ix::storeId('sa'), $ready['product'], true)));
        stockHold([new HoldLineDto($a, 1)], 'eg');
        $sa = Ix::storeId('sa');
        $eg = Ix::storeId('eg');
        $read = static fn (): array => app(ViewProductStockHandler::class)->handle(new ViewProductStock($ready['product'], [$a, $b], [$eg, $sa]));

        stockManager(['sa', 'eg']);
        $cards = $read();
        $sizes = static fn (ProductStoreStock $card): array => array_map(static fn (ProductSizeStock $size): array => [$size->variantId, $size->figures->inStock, $size->figures->available, $size->figures->badge], $card->sizes);

        expect(array_map(static fn (ProductStoreStock $card): string => $card->storeId, $cards))->toBe([$eg, $sa])
            ->and([$cards[0]->lastPieces, $cards[1]->lastPieces])->toBe([false, true])
            ->and([$cards[0]->wired, $cards[0]->off])->toBe([false, false])
            ->and($sizes($cards[0]))->toBe([[$a, 7, 6, 'LOW'], [$b, 30, 30, null]])
            ->and($cards[0]->sizes[0]->figures->holdingOrders)->toBe(1)
            ->and($sizes($cards[1]))->toBe([[$a, 4, 4, 'LOW'], [$b, 0, 0, 'OUT_OF_STOCK']]);

        $two = stockQueries($read);
        stockManager(['sa']);

        expect(array_map(static fn (ProductStoreStock $card): string => $card->storeId, $read()))->toBe([$sa])
            // One read for every card: two stores cost what one does.
            ->and(stockQueries($read))->toBe($two)
            // 4, and since the review of screens step 5 one more: which sizes are archived (Catalog's variantBriefs).
            ->and($two)->toBeLessThanOrEqual(5);

        $section = app(ProductStockSection::class);
        $part = $section->read($ready['product'], [$a, $b], [app(PlatformApi::class)->store(StoreId::fromString($sa)) ?? throw new LogicException('No store.')]);

        expect($section->component())->toBe('Inventory/ProductStock')
            ->and(array_keys($part))->toBe([$sa])
            ->and($part[$sa]['lastPieces'])->toBeTrue()
            ->and($part[$sa]['words']['inventory::admin_stock.reasons.wired'] ?? null)->toBe("The provider sets this store's stock.")
            ->and(array_map(static fn (array $registered): string => $registered['module'], app(InMemoryProductStoreSections::class)->all()))->toContain('inventory');

        stockManager(['ae']);

        expect($read())->toBe([]);
    });
});

describe('the Low Stock list (I2)', function () {
    it('orders the fewest available first, then by product name, then by the size\'s place, with each row\'s figures', function () {
        $bravo = stockProduct('Bravo', ['60 cm', '80 cm']);
        $alpha = stockProduct('Alpha');
        $zulu = stockProduct('Zulu');
        $mid = stockProduct('Mid');
        Ix::count('sa', [$bravo['variants'][1] => 2, $bravo['variants'][0] => 2, $alpha['variants'][0] => 2, $mid['variants'][0] => 50]);
        Fx::asSystem(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold(Ix::storeId('sa'), [$mid['variants'][0] => 60])));
        stockManager();

        $list = app(LowStockHandler::class)->handle(new LowStock(Ix::storeId('sa')));
        $ids = array_map(static fn (LowStockRow $row): string => $row->size->variantId, $list->rows);
        $last = $list->rows[4];

        expect($ids)->toBe([$zulu['variants'][0], $alpha['variants'][0], $bravo['variants'][0], $bravo['variants'][1], $mid['variants'][0]])
            ->and([$list->total, $list->next])->toBe([5, null])
            ->and([$last->figures->available, $last->figures->threshold, $last->figures->ownThreshold, $last->figures->badge])->toBe([50, 60, 60, 'LOW'])
            ->and([$list->rows[0]->figures->badge, $list->rows[0]->figures->ownThreshold])->toBe(['OUT_OF_STOCK', null])
            ->and([$list->rows[2]->size->values, $list->rows[2]->size->productNameEn])->toBe([['60 cm'], 'Bravo'])
            ->and($list->rows[2]->size->photoMediaId)->not->toBeNull();
    });

    it('pages 50 at a time after the cursor with the total, in the same few queries for 1 low size as for many', function () {
        stockProduct('Aa');
        stockManager();
        $read = static fn (?string $after = null): LowStockList => app(LowStockHandler::class)->handle(new LowStock(Ix::storeId('sa'), $after));
        $one = stockQueries($read);

        $many = stockProduct('Bb', array_map('strval', range(2, 55)));
        Ix::count('sa', array_fill_keys(array_slice($many['variants'], 0, 10), 3));
        $page = $read();
        $next = $read($page->next);
        $all = array_map(static fn (LowStockRow $row): string => $row->size->variantId, [...$page->rows, ...$next->rows]);

        expect([count($page->rows), $page->total, count($next->rows), $next->next])->toBe([50, 55, 5, null])
            ->and(array_unique($all))->toHaveCount(55)
            // The 10 counted at 3 come after the 45 at 0.
            ->and(array_slice($all, 45))->toBe(array_slice($many['variants'], 0, 10))
            // A cursor that is not one starts at the top.
            ->and($read('not a cursor')->rows[0]->size->variantId)->toBe($page->rows[0]->size->variantId)
            ->and(stockQueries($read))->toBe($one)
            ->and($one)->toBeLessThanOrEqual(7);
    });
});

describe('what the screens may offer (InventoryActionsForReader)', function () {
    it('answers each store the reader manages stock in: products, wired, off, and the reasons', function () {
        $sa = Ix::storeId('sa');
        $eg = Ix::storeId('eg');
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE, 'catalog.product.view'], ['sa']);
        $actions = app(InventoryActionsForReader::class);

        expect(array_map(static fn (StoreInventoryActions $store): array => [$store->storeId, $store->mayManageStock, $store->mayViewProducts, $store->wired, $store->off, $store->handChangeRefusal], $actions->stores()))
            ->toBe([[$sa, true, true, false, false, null]])
            ->and($actions->forStore($eg))->toBeNull()
            ->and([$actions->reasons()->wired, $actions->reasons()->archived])->toBe(["The provider sets this store's stock.", 'Archived in the catalog. Restore it to change its stock.']);

        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Ix::wired();
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(app(InventoryActionsForReader::class)->forStore($eg))->toEqual(new StoreInventoryActions($eg, true, true, true, true, "The provider sets this store's stock."));

        // Products alone open no Inventory screen.
        Cx::actAsStaffWith(['catalog.product.view'], ['sa']);

        expect(app(InventoryActionsForReader::class)->stores())->toBe([]);
    });
});

describe('holds before saving (Q20)', function () {
    it('says how many orders hold how many pieces of each size, in one query however many', function () {
        $ready = stockProduct('Held', ['60 cm', '80 cm']);
        [$held, $free] = $ready['variants'];
        Ix::count('sa', [$held => 10]);
        stockHold([new HoldLineDto($held, 3), new HoldLineDto($held, 1, gift: true)]);
        stockHold([new HoldLineDto($held, 1)]);
        stockManager();
        $read = static fn (): array => app(HoldsOnSizesHandler::class)->handle(new HoldsOnSizes(Ix::storeId('sa'), [$held, strtoupper($free)]));

        $answer = $read();
        $read();
        $queries = Cx::recordQueries();
        $read();

        expect(array_keys($answer))->toBe([$held, $free])
            ->and([$answer[$held]->orders, $answer[$held]->pieces, $answer[$held]->bought, $answer[$held]->gift])->toBe([2, 5, 4, 1])
            ->and([$answer[$free]->orders, $answer[$free]->pieces])->toBe([0, 0])
            ->and(count(array_filter($queries->getArrayCopy(), static fn (array $query): bool => str_contains($query['sql'], 'inventory.hold'))))->toBe(1);
    });
});

describe('refusals name their sizes, their words the box (Q2)', function () {
    it('names the size of a count below 0, a quantity below 1, a removal past what is in stock and a threshold out of bounds', function () {
        $ready = stockProduct('Refused', ['60 cm', '80 cm']);
        [$size, $other] = $ready['variants'];
        Ix::count('sa', [$size => 4]);
        stockManager();
        $sa = Ix::storeId('sa');
        $said = static fn (DomainError $error): array => [FormErrors::message($error), array_keys(FormErrors::rows($error))];

        $count = stockRefusal(fn () => app(SetStockHandler::class)->handle(new SetStock($sa, [$other => 3, $size => -1])));
        $quantity = stockRefusal(fn () => app(AdjustStockHandler::class)->handle(new AdjustStock($sa, $size, 'ADD', 0, 'RECEIVED')));
        $removal = stockRefusal(fn () => app(AdjustStockHandler::class)->handle(new AdjustStock($sa, $size, 'REMOVE', 5, 'OFFLINE_SALE')));
        $threshold = stockRefusal(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold($sa, [$other => 5, $size => 100_001])));

        expect($count)->toBeInstanceOf(QuantityInvalid::class)
            ->and($said($count))->toBe(['Count must be a whole number from 0 to 2,147,483,647.', [$size]])
            ->and($said($quantity))->toBe(['Quantity must be 1 or more.', [$size]])
            ->and($removal)->toBeInstanceOf(StockBelowZero::class)
            ->and($said($removal))->toBe(["Quantity can't be more than 4, what's in stock.", [$size]])
            ->and($threshold)->toBeInstanceOf(ThresholdInvalid::class)
            ->and($said($threshold))->toBe(['Threshold must be from 0 to 100,000.', [$size]])
            // Nothing saved: all or none.
            ->and(Ix::stock('sa', $other))->toBeNull();

        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($size)));
        $archived = stockRefusal(fn () => app(SetStockHandler::class)->handle(new SetStock($sa, [$other => 3, $size => 2])));

        expect($archived)->toBeInstanceOf(InvalidInventoryInput::class)
            ->and(array_keys(FormErrors::rows($archived)))->toBe([$size]);

        app()->setLocale('ar');

        expect(FormErrors::message($removal))->toBe('لا يمكن أن تزيد الكمية على 4، وهو ما في المخزون.');
    });
});

describe('Add Size (Q24)', function () {
    it('offers every ready size not listed, counts the one picked with a stocktake, and lists it from then on', function () {
        $listed = stockProduct('Listed');
        $giftOnly = stockProduct('Gift Only', ['60 cm', '80 cm'], []);
        [$picked, $left] = $giftOnly['variants'];
        stockManager();
        $choices = static fn (?string $search = null) => app(AddSizeChoicesHandler::class)->handle(new AddSizeChoices(Ix::storeId('sa'), $search));
        $ids = static fn (array $sizes): array => array_map(static fn (SizeView $size): string => $size->variantId, $sizes);

        expect($ids($choices()->rows))->toContain($picked, $left)
            ->and(in_array($listed['variants'][0], $ids($choices()->rows), true))->toBeFalse()
            // A search finding a listed size says so: "Already listed".
            ->and($ids($choices('listed')->rows))->toBe([])
            ->and($ids($choices('listed')->alreadyListed))->toBe([$listed['variants'][0]]);

        $saved = app(SetStockHandler::class)->handle(new SetStock(Ix::storeId('sa'), [$picked => 5]));
        $row = array_values(array_filter(stockList()->rows, static fn (StockListRow $row): bool => $row->size->variantId === $picked))[0] ?? null;

        expect($saved)->toEqual(new StockSaved)
            ->and([$row?->figures->inStock, $row?->size->switchedOn])->toBe([5, false])
            ->and($ids($choices('gift')->rows))->toBe([$left])
            ->and($ids($choices('gift')->alreadyListed))->toBe([$picked])
            ->and(stockQueries($choices))->toBeLessThanOrEqual(6);

        Ix::wired();

        expect(fn () => app(AddSizeChoicesHandler::class)->handle(new AddSizeChoices(Ix::storeId('sa'))))->toThrow(ProviderOwnsStock::class);
    });
});

describe('the save\'s answer: sizes left short (the amber toast)', function () {
    it('names each size a count or a change left short, with its orders and by how many - the event only when it went down', function () {
        $ready = stockProduct('Short', ['60 cm', '80 cm']);
        [$size, $other] = $ready['variants'];
        Ix::count('sa', [$size => 10, $other => 10]);
        stockHold([new HoldLineDto($size, 4)]);
        stockHold([new HoldLineDto($size, 3)]);
        Event::fake([HoldsShort::class]);
        stockManager();
        $sa = Ix::storeId('sa');

        $counted = app(SetStockHandler::class)->handle(new SetStock($sa, [$size => 5, $other => 2]));
        $added = app(AdjustStockHandler::class)->handle(new AdjustStock($sa, $size, 'ADD', 1, 'RECEIVED'));
        $enough = app(AdjustStockHandler::class)->handle(new AdjustStock($sa, $size, 'ADD', 5, 'RECEIVED'));
        $removed = app(AdjustStockHandler::class)->handle(new AdjustStock($sa, $size, 'REMOVE', 10, 'DAMAGED_LOST'));

        expect($counted->short)->toEqual([new ShortSize($size, 2, 2)])
            ->and($added->short)->toEqual([new ShortSize($size, 2, 1)])
            ->and($enough->short)->toBe([])
            ->and($removed->short)->toEqual([new ShortSize($size, 2, 6)]);

        Event::assertDispatchedTimes(HoldsShort::class, 2);
    });
});

/**
 * Every store wired, asking the database once a question as Sync's `ConnectionWiring` does - so a read's
 * counted queries include the wiring's, as on a real page.
 */
function stockWiredLikeSync(): void
{
    $stores = array_values(array_map('strval', DB::table('platform.stores')->pluck('id')->all()));

    app()->instance(StoreWiring::class, new readonly class($stores) implements StoreWiring
    {
        /**
         * @param  list<string>  $stores
         */
        public function __construct(private array $stores) {}

        public function isWired(StoreId $store): bool
        {
            DB::select('SELECT 1');

            return true;
        }

        public function wiredStores(): array
        {
            DB::select('SELECT 1');

            return $this->stores;
        }
    });
}

describe('a wired store\'s pages within their budget (review of step 4)', function () {
    it('reads the Stock list, a size\'s drawer and the Low Stock page - with what the screens may offer - in 15 queries at most', function () {
        $ready = stockProduct('Wired', array_map('strval', range(1, 12)));
        stockWiredLikeSync();
        $sa = StoreId::fromString(Ix::storeId('sa'));
        Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent($sa->value, array_slice($ready['variants'], 0, 3), true)));
        Fx::asSystem(fn () => app(ProviderStock::class)->apply($sa, array_fill_keys(array_slice($ready['variants'], 0, 6), 4), 'read-1'));
        stockHold([new HoldLineDto($ready['variants'][0], 1), new HoldLineDto($ready['variants'][4], 1, gift: true)]);
        stockManager();
        $actions = static fn () => app(InventoryActionsForReader::class)->stores();
        $list = stockQueries(static fn () => [stockList(), $actions()]);
        $drawer = stockQueries(static fn () => app(ViewStockHandler::class)->handle(new ViewStock($sa->value, $ready['variants'][0])));
        $low = stockQueries(static fn () => [app(LowStockHandler::class)->handle(new LowStock($sa->value)), $actions()]);

        expect([$list, $drawer, $low])->each->toBeLessThanOrEqual(15)
            // Measured, so a read that grows is seen: the Stock page 9, the drawer 5, the Low Stock page 8.
            ->and([$list, $drawer, $low])->toBe([9, 5, 8]);
    });
});

describe('the review\'s points (step 4)', function () {
    it('names a size already short that a count finds right, with no event', function () {
        [$size] = stockProduct('Short')['variants'];
        Ix::count('sa', [$size => 5]);
        stockHold([new HoldLineDto($size, 5)]);
        Ix::count('sa', [$size => 2]);
        Event::fake([HoldsShort::class]);
        stockManager();

        $saved = app(SetStockHandler::class)->handle(new SetStock(Ix::storeId('sa'), [$size => 2]));

        expect($saved->short)->toEqual([new ShortSize($size, 1, 3)]);
        Event::assertNotDispatched(HoldsShort::class);
    });

    it('marks every bad row of a batch at once', function () {
        $ready = stockProduct('Batch', ['60 cm', '80 cm', '100 cm']);
        [$a, $b, $c] = $ready['variants'];
        stockManager();
        $sa = Ix::storeId('sa');
        $rows = static fn (callable $work): array => array_keys(FormErrors::rows(stockRefusal($work)));

        expect($rows(fn () => app(SetStockHandler::class)->handle(new SetStock($sa, [$a => -1, $b => 3, $c => -2]))))->toEqualCanonicalizing([$a, $c])
            // One size written two ways with two counts: both rows.
            ->and($rows(fn () => app(SetStockHandler::class)->handle(new SetStock($sa, [$a => 1, strtoupper($a) => 2, $b => 3]))))->toEqualCanonicalizing([$a, strtoupper($a)])
            ->and($rows(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold($sa, [$a => -1, $b => 5, $c => 100_001]))))->toEqualCanonicalizing([$a, $c])
            ->and($rows(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold($sa, [$b => 1, strtoupper($b) => null]))))->toEqualCanonicalizing([$b, strtoupper($b)]);
    });

    it('counts a size the store does not sell only when its product is ready; the store file is not held to it', function () {
        $draft = Px::product('Draft');
        $draftSize = Px::variant($draft, '7300002');
        $readyElsewhere = stockProduct('Ready', stores: []);
        stockManager();
        $sa = Ix::storeId('sa');

        $refused = stockRefusal(fn () => app(SetStockHandler::class)->handle(new SetStock($sa, [$readyElsewhere['variants'][0] => 2, $draftSize => 4])));

        expect($refused)->toBeInstanceOf(InvalidInventoryInput::class)
            ->and(array_keys(FormErrors::rows($refused)))->toBe([$draftSize])
            ->and(Ix::stock('sa', $readyElsewhere['variants'][0]))->toBeNull();

        app(SetStockHandler::class)->handle(new SetStock($sa, [$readyElsewhere['variants'][0] => 2]));
        Fx::asSystem(fn () => app(SetStockFromFileHandler::class)->handle(new SetStockFromFile($sa, $draftSize, 6, 'file:draft')));

        expect([Ix::stock('sa', $readyElsewhere['variants'][0])?->in_stock, Ix::stock('sa', $draftSize)?->in_stock])->toBe([2, 6]);
    });

    it('names a return\'s order in the history, and a Super Admin as hidden to anyone else', function () {
        [$size] = stockProduct('Returned')['variants'];
        Fx::actAsStaff(Fx::staff(superAdmin: true, firstName: 'Root'));
        app(SetStockHandler::class)->handle(new SetStock(Ix::storeId('sa'), [$size => 10]));
        $order = stockHold([new HoldLineDto($size, 2)]);
        app(InventoryApi::class)->ship($order, [new HoldLineDto($size, 2)]);
        app(InventoryApi::class)->returned($order, 'return-1', [new HoldLineDto($size, 1)]);
        stockManager();

        $lines = app(StockHistoryHandler::class)->handle(new StockHistory(Ix::storeId('sa'), $size))->lines;

        expect([$lines[0]->reason, $lines[0]->who, $lines[0]->orderId])->toBe(['RETURNED', 'ORDER', $order])
            ->and([$lines[2]->reason, $lines[2]->who, $lines[2]->staffHidden])->toBe(['STOCKTAKE', 'STAFF', true])
            ->and(str_contains((string) $lines[2]->staffName, 'Root'))->toBeFalse();
    });

    it('holds and figures in a wired store: a gift held, a bought line only noted', function () {
        [$size] = stockProduct('Wired Gift')['variants'];
        Ix::wired();
        $sa = StoreId::fromString(Ix::storeId('sa'));
        Fx::asSystem(fn () => app(ProviderStock::class)->apply($sa, [$size => 10], 'read-1'));
        // An ordinary size: switched off by staff, as every size is stock-dependent by default (§9.2 #24).
        Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent($sa->value, [$size], false)));
        stockHold([new HoldLineDto($size, 3), new HoldLineDto($size, 2, gift: true)]);
        stockManager();

        $holds = app(HoldsOnSizesHandler::class)->handle(new HoldsOnSizes($sa->value, [$size]))[$size];
        $figures = app(ViewStockHandler::class)->handle(new ViewStock($sa->value, $size))->figures;

        expect([$holds->orders, $holds->pieces, $holds->bought, $holds->gift])->toBe([1, 2, 0, 2])
            ->and([$figures->inStock, $figures->held, $figures->available, $figures->countsOnStock, $figures->badge, $figures->holdingOrders])->toBe([10, 2, 8, false, 'LOW', 1]);
    });
});

describe('who may read (review of step 4)', function () {
    it('refuses an off store to whoever may not switch stores, on every store read', function () {
        [$size] = stockProduct('Off', stores: ['eg'])['variants'];
        $eg = Ix::storeId('eg');
        // Made while the store is on: the fixtures look up stores that are.
        $manager = stockManager(['eg']);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsStaff($manager);

        foreach ([
            fn () => app(ListStockHandler::class)->handle(new ListStock($eg)),
            fn () => app(ViewStockHandler::class)->handle(new ViewStock($eg, $size)),
            fn () => app(StockHistoryHandler::class)->handle(new StockHistory($eg, $size)),
            fn () => app(LowStockHandler::class)->handle(new LowStock($eg)),
            fn () => app(HoldsOnSizesHandler::class)->handle(new HoldsOnSizes($eg, [$size])),
            fn () => app(AddSizeChoicesHandler::class)->handle(new AddSizeChoices($eg)),
        ] as $read) {
            expect(stockRefusal($read))->toBeInstanceOf(StoreOff::class);
        }
    });

    it('refuses a store where the reader does not manage stock', function () {
        [$size] = stockProduct('Elsewhere')['variants'];
        $sa = Ix::storeId('sa');
        stockManager(['eg']);

        foreach ([
            fn () => app(ViewStockHandler::class)->handle(new ViewStock($sa, $size)),
            fn () => app(StockHistoryHandler::class)->handle(new StockHistory($sa, $size)),
            fn () => app(LowStockHandler::class)->handle(new LowStock($sa)),
            fn () => app(HoldsOnSizesHandler::class)->handle(new HoldsOnSizes($sa, [$size])),
            fn () => app(AddSizeChoicesHandler::class)->handle(new AddSizeChoices($sa)),
        ] as $read) {
            expect($read)->toThrow(Unauthorized::class);
        }
    });

    it('leaves an off store off a product\'s page for whoever may not switch stores, and shows it to a Super Admin', function () {
        $ready = stockProduct('Both', stores: ['sa', 'eg']);
        $sa = Ix::storeId('sa');
        $eg = Ix::storeId('eg');
        $manager = stockManager(['sa', 'eg']);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $read = static fn (): array => array_map(
            static fn (ProductStoreStock $card): array => [$card->storeId, $card->off],
            app(ViewProductStockHandler::class)->handle(new ViewProductStock($ready['product'], $ready['variants'], [$sa, $eg])),
        );

        Fx::actAsStaff($manager);

        expect($read())->toBe([[$sa, false]]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect($read())->toBe([[$sa, false], [$eg, true]]);
    });
});

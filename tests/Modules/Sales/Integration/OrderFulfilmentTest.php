<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Sales\Application\Command\AllowShipUnpaid\AllowShipUnpaid;
use Modules\Sales\Application\Command\AllowShipUnpaid\AllowShipUnpaidHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBack;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBackHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\TickReducedInProvider\TickReducedInProvider;
use Modules\Sales\Application\Command\TickReducedInProvider\TickReducedInProviderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotShippable;
use Modules\Sales\Domain\Service\OrderStatuses;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Events\OrderDelivered;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Shipping, delivery and a parcel that comes back (sales.md §1.5): one shipment per order, only approved
| and paid — or allowed unpaid — its stock taken once; "reduced in the provider" ticked in a wired store;
| delivered: completed, the return window fixed, the points earned, told; came back: undelivered, nothing
| due, the points used back, only the pieces whose stock was taken put back.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * An order placed (2 of the first size, 1 of the second), approved; staff who fulfil, acting.
 *
 * @return array{order: string, variants: list<string>, customer: string, earns: int}
 */
function fulfilled(bool $withPoints = false, bool $withGift = false, bool $approve = true, bool $wired = false, bool $dependent = false): array
{
    $ready = Cx::ready();

    if ($withPoints) {
        Pr::ceiling(100);
        Lx::programme();
        Lx::deliver($ready['customer'], 1_000_00);
    }

    if ($withGift) {
        Pr::giftLevel($ready['variants'][1], 100_00);
    }

    // Wired before the order is placed (its hold remembers), after the stock was counted (then the provider's).
    if ($wired) {
        Ix::wired();
        // Ordinary sizes: staff switched both off, as every size is stock-dependent by default
        // (inventory.md §9.2 #24).
        Ix::switchOff('sa', $ready['variants']);
    }

    // The first size's normal sales made to depend on its stock again (a wired store's switch).
    if ($dependent) {
        app(InventoryApi::class)->makeStockDependent(StoreId::fromString(Fx::storeId('sa')), [$ready['variants'][0]]);
    }

    $quote = Cx::start($ready['address'], usePoints: $withPoints);
    $order = Cx::place($quote->id)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));

    if ($approve) {
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    }

    return ['order' => $order, 'variants' => $ready['variants'], 'customer' => $ready['customer'], 'earns' => $quote->earningPreview];
}

/** Paid in full, by hand. */
function fulfilledPaid(string $order): void
{
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
}

function fulfilledShip(string $order, string $carrier = 'SMSA', ?string $tracking = 'T-100'): void
{
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, $carrier, $tracking));
}

function fulfilledRow(string $order): ?stdClass
{
    return DB::table('sales.orders')->where('id', $order)->first();
}

function fulfilledInStock(string $variantId): int
{
    return (int) Ix::stock('sa', $variantId)?->in_stock;
}

/** In a wired store, what came back is held on our side until the provider's number next changes. */
function fulfilledBackExtra(string $variantId): int
{
    return (int) Ix::stock('sa', $variantId)?->returned_extra;
}

describe('shipping', function () {
    it('ships a paid approved order once, its stock taken off, kept and audited', function () {
        ['order' => $order, 'variants' => $variants] = fulfilled();
        fulfilledPaid($order);

        fulfilledShip($order);

        $row = fulfilledRow($order);
        expect([$row?->shipment_status, $row?->carrier, $row?->tracking_number, $row?->status])->toBe(['SHIPPED', 'SMSA', 'T-100', 'APPROVED'])
            ->and([fulfilledInStock($variants[0]), fulfilledInStock($variants[1])])->toBe([98, 99])
            ->and(Fx::audits('sales.order.shipped', $order))->toBe(1)
            ->and(fn () => fulfilledShip($order))->toThrow(InvalidOrderChange::class)
            ->and([fulfilledInStock($variants[0]), fulfilledInStock($variants[1])])->toBe([98, 99]);
    });

    it('ships unpaid only when staff allowed it, and an order flagged short', function () {
        ['order' => $order] = fulfilled();
        DB::table('sales.orders')->where('id', $order)->update(['short_of_stock_at' => CarbonImmutable::now()]);

        expect(fn () => fulfilledShip($order))->toThrow(NotShippable::class);

        app(AllowShipUnpaidHandler::class)->handle(new AllowShipUnpaid($order, true));
        fulfilledShip($order, tracking: null);

        expect(fulfilledRow($order)?->shipment_status)->toBe('SHIPPED')
            ->and(fulfilledRow($order)?->tracking_number)->toBeNull();
    });

    it('refuses to ship an order not approved, or with no carrier', function () {
        ['order' => $order] = fulfilled(approve: false);
        fulfilledPaid($order);

        expect(fn () => fulfilledShip($order))->toThrow(NotShippable::class)
            ->and(fn () => fulfilledShip($order, '  '))->toThrow(InvalidSalesAttribute::class)
            ->and(fn () => fulfilledShip($order, 'SMSA', str_repeat('1', 101)))->toThrow(InvalidSalesAttribute::class);
    });
});

describe('delivered', function () {
    it('completes the order, fixes its return window, earns its points and tells of it', function () {
        Lx::programme();
        ['order' => $order, 'customer' => $customer, 'earns' => $earns] = fulfilled();
        Cx::setting(SalesSettings::RETURN_WINDOW_DAYS, 30);
        fulfilledPaid($order);
        fulfilledShip($order);
        // Two days ahead of now, never behind it (lesson 221), at 15:30:45.
        $delivered = CarbonImmutable::now()->addDays(2)->setTime(15, 30, 45);
        CarbonImmutable::setTestNow($delivered);
        $before = app(LoyaltyApi::class)->balance($customer, Fx::storeId('sa'))->points;

        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));

        $row = fulfilledRow($order);
        expect([$row?->status, $row?->shipment_status])->toBe(['COMPLETED', 'DELIVERED'])
            ->and(CarbonImmutable::parse((string) $row?->return_deadline_at)->format('Y-m-d H:i'))->toBe($delivered->addDays(30)->format('Y-m-d H:i'))
            ->and(CarbonImmutable::parse((string) $row?->return_deadline_at)->format('s'))->toBe('00')
            ->and($earns)->toBeGreaterThan(0)
            ->and(app(LoyaltyApi::class)->balance($customer, Fx::storeId('sa'))->points)->toBe($before + $earns)
            ->and(DB::table('public.outbox_messages')->where('type', OrderDelivered::class)->where('payload->orderId', $order)->count())->toBe(1)
            ->and(Fx::audits('sales.order.delivered', $order))->toBe(1)
            ->and(fn () => app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order)))->toThrow(InvalidOrderChange::class);
    });

    it('refuses to deliver what has not shipped', function () {
        ['order' => $order] = fulfilled();

        expect(fn () => app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order)))->toThrow(InvalidOrderChange::class);
    });
});

describe('came back', function () {
    it('settles a parcel that came back as never delivered, putting back the pieces chosen', function () {
        ['order' => $order, 'variants' => $variants, 'customer' => $customer] = fulfilled(withPoints: true);
        fulfilledPaid($order);
        fulfilledShip($order);
        $points = app(LoyaltyApi::class)->balance($customer, Fx::storeId('sa'))->points;
        $used = (int) fulfilledRow($order)?->points_used;

        // One of the first size was damaged: left out.
        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [['variantId' => $variants[0], 'quantity' => 1], ['variantId' => $variants[1], 'quantity' => 1]]));

        $row = fulfilledRow($order);
        expect([$row?->status, $row?->shipment_status, (int) $row?->due_minor])->toBe(['UNDELIVERED', 'CAME_BACK', 0])
            ->and($row?->came_back_id)->not->toBeNull()
            ->and([fulfilledInStock($variants[0]), fulfilledInStock($variants[1])])->toBe([99, 100])
            ->and(app(LoyaltyApi::class)->balance($customer, Fx::storeId('sa'))->points)->toBe($points + $used)
            ->and(Fx::audits('sales.order.came_back', $order))->toBe(1)
            ->and(fn () => app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [])))->toThrow(InvalidOrderChange::class);
    });

    it('takes a parcel lost for good, with nothing put back', function () {
        ['order' => $order, 'variants' => $variants] = fulfilled();
        fulfilledPaid($order);
        fulfilledShip($order);

        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, []));

        expect(fulfilledRow($order)?->status)->toBe('UNDELIVERED')
            ->and([fulfilledInStock($variants[0]), fulfilledInStock($variants[1])])->toBe([98, 99]);
    });

    it('puts back no more than the parcel held', function (array $line) {
        ['order' => $order, 'variants' => $variants] = fulfilled();
        fulfilledPaid($order);
        fulfilledShip($order);

        expect(fn () => app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [['variantId' => $variants[$line[0]], 'quantity' => $line[1], 'gift' => $line[2]]])))->toThrow(InvalidSalesAttribute::class)
            ->and(fulfilledRow($order)?->shipment_status)->toBe('SHIPPED');
    })->with([
        'more than shipped' => [[0, 3, false]],
        'a gift never given' => [[1, 1, true]],
        'none' => [[0, 0, false]],
    ]);

    it('refuses an order not shipped', function () {
        ['order' => $order] = fulfilled();

        expect(fn () => app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [])))->toThrow(InvalidOrderChange::class);
    });
});

describe('reduced in the provider', function () {
    it('ticks the gift in a wired store once, ending its hold, and refuses a size that does not count on stock', function () {
        ['order' => $order, 'variants' => $variants] = fulfilled(withGift: true, wired: true);
        $tick = fn (string ...$ids) => app(TickReducedInProviderHandler::class)->handle(new TickReducedInProvider($order, array_values($ids)));

        $tick($variants[1]);
        $tick($variants[1]);

        expect(DB::table('sales.order_ticks')->where('order_id', $order)->pluck('variant_id')->all())->toBe([$variants[1]])
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'TICKED_REDUCED')->count())->toBe(1)
            ->and(fn () => $tick($variants[0]))->toThrow(InvalidSalesAttribute::class)
            ->and(fn () => $tick('not-a-size'))->toThrow(InvalidSalesAttribute::class);
    });

    it('ticks nothing in a store with no provider, or on an order waiting', function (bool $wired, bool $approve) {
        ['order' => $order, 'variants' => $variants] = fulfilled(withGift: true, approve: $approve, wired: $wired);

        expect(fn () => app(TickReducedInProviderHandler::class)->handle(new TickReducedInProvider($order, [$variants[1]])))->toThrow(InvalidOrderChange::class);
    })->with([
        'no provider' => [false, true],
        'waiting' => [true, false],
    ]);

    it('puts back only the ticked pieces of a parcel that came back in a wired store', function () {
        ['order' => $order, 'variants' => $variants] = fulfilled(withGift: true, wired: true);
        fulfilledPaid($order);
        fulfilledShip($order);
        app(TickReducedInProviderHandler::class)->handle(new TickReducedInProvider($order, [$variants[1]]));
        $giftBack = fulfilledBackExtra($variants[1]);
        $sizeBack = fulfilledBackExtra($variants[0]);

        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [
            ['variantId' => $variants[0], 'quantity' => 2], ['variantId' => $variants[1], 'quantity' => 1, 'gift' => true],
        ]));

        expect(fulfilledBackExtra($variants[0]))->toBe($sizeBack)
            ->and(fulfilledBackExtra($variants[1]))->toBe($giftBack + 1);
    });
});

describe('what the review asked for', function () {
    it('audits the carrier and the tracking number as written only, and keeps them out of the history', function () {
        ['order' => $order] = fulfilled();
        fulfilledPaid($order);

        fulfilledShip($order, 'Aramex', 'AWB-555-777');

        $audit = (string) DB::table('platform.audit_entries')->where('action', 'sales.order.shipped')->where('subject_id', $order)->value('changes');
        $history = (string) DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'SHIPPED')->value('detail');
        expect($audit)->toContain('tracking_number')
            ->and($audit)->not->toContain('AWB-555-777')
            ->and($audit)->not->toContain('Aramex')
            ->and($history)->not->toContain('AWB-555-777')
            ->and($history)->not->toContain('Aramex')
            ->and(fulfilledRow($order)?->tracking_number)->toBe('AWB-555-777');
    });

    it('keeps on the order the facts its hold was placed with', function (bool $wired, bool $dependent, array $counts) {
        ['order' => $order, 'variants' => $variants] = fulfilled(withGift: true, wired: $wired, dependent: $dependent);

        $lines = DB::table('sales.order_lines')->where('order_id', $order)->orderBy('is_gift')->orderBy('variant_id')->get()
            ->map(fn ($line): array => [array_search($line->variant_id, $variants, true), (bool) $line->is_gift, (bool) $line->counts_on_stock])->all();

        expect((bool) fulfilledRow($order)?->wired)->toBe($wired)
            ->and($lines)->toEqualCanonicalizing($counts);
    })->with([
        'no provider' => [false, false, [[0, false, true], [1, false, true], [1, true, true]]],
        'wired' => [true, false, [[0, false, false], [1, false, false], [1, true, true]]],
        'wired, the first size stock-dependent' => [true, true, [[0, false, true], [1, false, false], [1, true, true]]],
    ]);

    it('ticks a stock-dependent size, and on a delivered order', function () {
        ['order' => $order, 'variants' => $variants] = fulfilled(wired: true, dependent: true);
        fulfilledPaid($order);
        fulfilledShip($order);
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));

        app(TickReducedInProviderHandler::class)->handle(new TickReducedInProvider($order, [$variants[0]]));

        expect(DB::table('sales.order_ticks')->where('order_id', $order)->pluck('variant_id')->all())->toBe([$variants[0]]);
    });

    it('frees what an unticked stock-dependent size still holds when its parcel comes back, and counts none of it back', function () {
        ['order' => $order, 'variants' => $variants] = fulfilled(wired: true, dependent: true);
        fulfilledPaid($order);
        fulfilledShip($order);
        $held = (int) Ix::stock('sa', $variants[0])?->held;
        $back = fulfilledBackExtra($variants[0]);

        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [['variantId' => $variants[0], 'quantity' => 2]]));

        expect($held)->toBe(2)
            ->and((int) Ix::stock('sa', $variants[0])?->held)->toBe(0)
            ->and(fulfilledBackExtra($variants[0]))->toBe($back);
    });

    it('goes by the facts of the order as placed when the store was wired since', function () {
        ['order' => $order, 'variants' => $variants] = fulfilled();
        fulfilledPaid($order);
        fulfilledShip($order);
        Ix::wired();

        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [['variantId' => $variants[0], 'quantity' => 2]]));

        expect(fulfilledInStock($variants[0]))->toBe(100)
            ->and(fn () => app(TickReducedInProviderHandler::class)->handle(new TickReducedInProvider($order, [$variants[0]])))->toThrow(InvalidOrderChange::class);
    });

    it('ships a size held in two parts as one', function () {
        ['order' => $order, 'variants' => $variants] = fulfilled();
        fulfilledPaid($order);
        $line = DB::table('sales.order_lines')->where('order_id', $order)->where('variant_id', $variants[0])->where('is_gift', false)->first();
        DB::table('sales.order_lines')->where('id', $line?->id)->update(['quantity' => 1, 'total_minor' => 100_00, 'vat_share_minor' => 0]);
        DB::table('sales.order_lines')->insert([...(array) $line, 'id' => strtolower((string) Str::ulid()), 'quantity' => 1, 'total_minor' => 100_00, 'vat_share_minor' => 0]);

        fulfilledShip($order);

        expect(fulfilledInStock($variants[0]))->toBe(98);
    });

    it('names the size when a piece put back is no size', function () {
        ['order' => $order] = fulfilled();
        fulfilledPaid($order);
        fulfilledShip($order);

        expect(Pr::thrown(fn () => app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [['variantId' => 'box', 'quantity' => 1]])), InvalidSalesAttribute::class)->attribute)->toBe('variant');
    });
});

describe('what the module pass added (§8)', function () {
    it('ships an order holding more than is due without "may ship unpaid"', function () {
        ['order' => $order] = fulfilled();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor + 100')]);

        fulfilledShip($order);

        expect([fulfilledRow($order)?->shipment_status, (bool) fulfilledRow($order)?->may_ship_unpaid])->toBe(['SHIPPED', false]);
    });

    it('keeps the coupon counted when a parcel comes back, the customer seeing it cancelled and then refunded', function () {
        $ready = Cx::ready();
        Pr::ceiling(50);
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $order = Cx::place(Cx::start($ready['address'])->id)->id;
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        fulfilledPaid($order);
        fulfilledShip($order);
        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, []));
        $seen = function () use ($order): CustomerOrderStatus {
            $row = fulfilledRow($order);

            return OrderStatuses::customer((string) $row?->status, (string) $row?->shipment_status, OrderStatuses::payment((int) $row?->due_minor, (int) $row?->paid_minor, (int) $row?->refunded_minor));
        };

        $before = $seen();
        DB::table('sales.orders')->where('id', $order)->update(['refunded_minor' => DB::raw('paid_minor')]);

        expect(DB::table('promotions.coupon_uses')->where('order_id', $order)->count())->toBe(1)
            ->and([$before, $seen()])->toBe([CustomerOrderStatus::Cancelled, CustomerOrderStatus::Refunded]);
    });
});

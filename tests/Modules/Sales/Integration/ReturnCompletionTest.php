<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturn;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturnHandler;
use Modules\Sales\Application\Command\EditOrder\EditOrder;
use Modules\Sales\Application\Command\EditOrder\EditOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturn;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturnHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Public\Contracts\SalesApi;
use Modules\Sales\Public\Events\ReturnCompleted;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A return received and completed (sales.md §1.8): the pieces that came, those going back into stock told
| to Inventory; the refund by running totals — every piece's goods and VAT shares, the shipping with the
| return that brings every bought piece back — off what is due; Loyalty settled; `ReturnCompleted`; and
| what Sales tells Feedback of purchases and returns (§2.1).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * An order delivered — two of the first size at 100.00, one of the second at 50.00, a 15% coupon, points,
 * a 25.00 shipping fee, the gift if asked — and staff who manage returns, acting.
 *
 * @return array{order: string, customer: string, address: string, product: string, variants: list<string>, lines: array<string, string>, staff: string}
 */
function completionOrder(bool $withGift = false): array
{
    $ready = Cx::ready();
    Cx::setting(SalesSettings::FLAT_FEE, 25_00);
    Pr::ceiling(100);
    Pr::coupon('SUMMER-15');
    app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
    Lx::programme();
    Lx::deliver($ready['customer'], 1_000_00);

    if ($withGift) {
        Pr::giftLevel($ready['variants'][1], 100_00);
    }

    $order = Cx::place(Cx::start($ready['address'], usePoints: true)->id)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, 'SMSA', null));
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    $lines = [];

    foreach (DB::table('sales.order_lines')->where('order_id', $order)->get(['id', 'variant_id', 'is_gift']) as $line) {
        $lines[$line->is_gift ? 'gift' : ($line->variant_id === $ready['variants'][0] ? 'first' : 'second')] = (string) $line->id;
    }

    $staff = Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['sa']);
    Fx::actAsStaff($staff);

    return ['order' => $order, 'customer' => $ready['customer'], 'address' => $ready['address'], 'product' => $ready['product'], 'variants' => $ready['variants'], 'lines' => $lines, 'staff' => $staff];
}

/**
 * Asked by the customer and accepted by staff, acting as staff again.
 *
 * @param  array{order: string, customer: string, staff: string}  $sold
 * @param  array<string, int>  $pieces  order line id => pieces
 */
function completionAccepted(array $sold, array $pieces): string
{
    Fx::actAsCustomer($sold['customer']);
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($sold['order'], 'DAMAGED', null, array_map(
        fn (string $lineId, int $quantity): array => ['orderLineId' => $lineId, 'quantity' => $quantity], array_keys($pieces), array_values($pieces),
    )));
    Fx::actAsStaff($sold['staff']);
    app(AcceptReturnHandler::class)->handle(new AcceptReturn($return));

    return $return;
}

/**
 * @param  array<string, array{int, int}>  $counts  order line id => [received, restocked]
 */
function completionReceive(string $return, array $counts): void
{
    app(ReceiveReturnHandler::class)->handle(new ReceiveReturn($return, array_map(
        fn (string $lineId, array $count): array => ['orderLineId' => $lineId, 'received' => $count[0], 'restocked' => $count[1]], array_keys($counts), array_values($counts),
    )));
}

function completionComplete(string $return): int
{
    return app(CompleteReturnHandler::class)->handle(new CompleteReturn($return));
}

function completionOrderRow(string $order): ?stdClass
{
    return DB::table('sales.orders')->where('id', $order)->first();
}

describe('completing', function () {
    it('refunds a whole order to its total over two returns, the shipping with the last, its points settled', function () {
        $sold = completionOrder();
        ['order' => $order, 'lines' => $lines] = $sold;
        $total = (int) completionOrderRow($order)?->order_total_minor;

        $first = completionAccepted($sold, [$lines['first'] => 1]);
        completionReceive($first, [$lines['first'] => [1, 1]]);
        $refundFirst = completionComplete($first);
        $dueBetween = (int) completionOrderRow($order)?->due_minor;

        $second = completionAccepted($sold, [$lines['first'] => 1, $lines['second'] => 1]);
        completionReceive($second, [$lines['first'] => [1, 1], $lines['second'] => [1, 1]]);
        $refundSecond = completionComplete($second);

        $row = completionOrderRow($order);
        expect($refundFirst + $refundSecond)->toBe($total)
            ->and($dueBetween)->toBe($total - $refundFirst)
            ->and((int) $row?->due_minor)->toBe(0)
            ->and(DB::table('sales.returns')->where('id', $first)->value('shipping_refunded'))->toBeFalse()
            ->and(DB::table('sales.returns')->where('id', $second)->value('shipping_refunded'))->toBeTrue()
            ->and((int) DB::table('sales.returns')->where('id', $second)->value('refund_due_minor'))->toBe($refundSecond)
            // Loyalty settled each return once, the points' whole discount between them.
            ->and((int) DB::table('loyalty.order_returns')->where('order_id', $order)->sum('returned_discount_minor'))->toBe((int) $row?->points_discount_minor)
            ->and((int) DB::table('loyalty.order_returns')->where('order_id', $order)->sum('returned_goods_minor'))->toBe((int) $row?->goods_total_minor)
            ->and(DB::table('public.outbox_messages')->where('type', ReturnCompleted::class)->count())->toBe(2)
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'RETURN_COMPLETED')->count())->toBe(2)
            ->and(Fx::audits('sales.order.return_completed', $order))->toBe(2);
    });

    it('refunds only the pieces that came, and nothing for the gift', function () {
        $sold = completionOrder(withGift: true);
        ['order' => $order, 'lines' => $lines] = $sold;
        $line = DB::table('sales.order_lines')->where('id', $lines['first'])->first();
        $return = completionAccepted($sold, [$lines['first'] => 2, $lines['gift'] => 1]);
        $giftStock = (int) Ix::stock('sa', $sold['variants'][1])?->in_stock;

        completionReceive($return, [$lines['first'] => [1, 0], $lines['gift'] => [1, 1]]);
        $refund = completionComplete($return);

        // One of two pieces: half of the goods share and of the VAT share, floored.
        $goods = intdiv((int) $line?->total_minor - (int) $line?->coupon_share_minor - (int) $line?->points_share_minor, 2);
        expect($refund)->toBe($goods + intdiv((int) $line?->vat_share_minor, 2))
            ->and(DB::table('sales.returns')->where('id', $return)->value('shipping_refunded'))->toBeFalse()
            // The gift went back into stock, as the gift.
            ->and((int) Ix::stock('sa', $sold['variants'][1])?->in_stock)->toBe($giftStock + 1);
    });

    it('stays exact when two received returns complete the other way round, the shipping with the last', function () {
        $sold = completionOrder();
        ['order' => $order, 'lines' => $lines] = $sold;
        $total = (int) completionOrderRow($order)?->order_total_minor;
        $first = completionAccepted($sold, [$lines['first'] => 1, $lines['second'] => 1]);
        $second = completionAccepted($sold, [$lines['first'] => 1]);
        completionReceive($first, [$lines['first'] => [1, 1], $lines['second'] => [1, 1]]);
        completionReceive($second, [$lines['first'] => [1, 1]]);

        $refunds = completionComplete($second) + completionComplete($first);

        expect($refunds)->toBe($total)
            ->and(DB::table('sales.returns')->where('id', $first)->value('shipping_refunded'))->toBeTrue()
            ->and(DB::table('sales.returns')->where('id', $second)->value('shipping_refunded'))->toBeFalse()
            ->and((int) completionOrderRow($order)?->due_minor)->toBe(0);
    });

    it('leaves everything as it was when Loyalty refuses the settlement', function () {
        $sold = completionOrder();
        $return = completionAccepted($sold, [$sold['lines']['first'] => 1]);
        completionReceive($return, [$sold['lines']['first'] => [1, 1]]);
        $due = (int) completionOrderRow($sold['order'])?->due_minor;
        $loyalty = Mockery::mock(LoyaltyApi::class);
        $loyalty->shouldReceive('orderReturned')->andThrow(new RuntimeException('Loyalty refused.'));
        app()->instance(LoyaltyApi::class, $loyalty);

        expect(fn () => completionComplete($return))->toThrow(RuntimeException::class, 'Loyalty refused.')
            ->and(DB::table('sales.returns')->where('id', $return)->value('status'))->toBe('RECEIVED')
            ->and((int) completionOrderRow($sold['order'])?->due_minor)->toBe($due)
            ->and(DB::table('public.outbox_messages')->where('type', ReturnCompleted::class)->count())->toBe(0)
            ->and(DB::table('sales.order_history')->where('order_id', $sold['order'])->where('kind', 'RETURN_COMPLETED')->count())->toBe(0);
    });

    it('completes a return of which nothing came, refunding nothing', function () {
        $sold = completionOrder();
        $return = completionAccepted($sold, [$sold['lines']['first'] => 1]);
        $due = (int) completionOrderRow($sold['order'])?->due_minor;

        completionReceive($return, [$sold['lines']['first'] => [0, 0]]);

        expect(completionComplete($return))->toBe(0)
            ->and((int) completionOrderRow($sold['order'])?->due_minor)->toBe($due);
    });
});

describe('receiving', function () {
    it('puts back into stock only the pieces staff send back', function () {
        $sold = completionOrder();
        ['lines' => $lines, 'variants' => $variants] = $sold;
        $before = (int) Ix::stock('sa', $variants[0])?->in_stock;
        $return = completionAccepted($sold, [$lines['first'] => 2]);

        completionReceive($return, [$lines['first'] => [2, 1]]);

        expect((int) Ix::stock('sa', $variants[0])?->in_stock)->toBe($before + 1)
            ->and(DB::table('sales.return_lines')->where('return_id', $return)->first(['received_quantity', 'restocked_quantity']))
            ->toEqual((object) ['received_quantity' => 2, 'restocked_quantity' => 1])
            ->and(DB::table('sales.returns')->where('id', $return)->value('status'))->toBe('RECEIVED');
    });

    it('sends Inventory one line a size when a return holds two parts of it (an edited order)', function () {
        $ready = Cx::ready();
        $order = Cx::place(Cx::start($ready['address'])->id)->id;
        $staff = Fx::staffWith([SalesPermissions::ORDER_EDIT, SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::RETURN_MANAGE], ['sa']);
        Fx::actAsStaff($staff);
        Rx::set('sa', [$ready['variants'][0] => '120.00']);
        app(EditOrderHandler::class)->handle(new EditOrder($order, 'Called the customer', [
            ['variantId' => $ready['variants'][0], 'mode' => 'RETAIL', 'quantity' => 3], ['variantId' => $ready['variants'][1], 'mode' => 'RETAIL', 'quantity' => 1],
        ], null, 'KEEP'));
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, 'SMSA', null));
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
        $parts = DB::table('sales.order_lines')->where('order_id', $order)->where('variant_id', $ready['variants'][0])->pluck('quantity', 'id')->map(fn ($q): int => (int) $q)->all();
        $before = (int) Ix::stock('sa', $ready['variants'][0])?->in_stock;

        $return = completionAccepted(['order' => $order, 'customer' => $ready['customer'], 'staff' => $staff], $parts);
        completionReceive($return, array_map(fn (int $pieces): array => [$pieces, $pieces], $parts));

        expect($parts)->toHaveCount(2)
            ->and((int) Ix::stock('sa', $ready['variants'][0])?->in_stock)->toBe($before + 3);
    });

    it('takes every line of the return once, never more than asked', function (Closure $counts, string $attribute) {
        $sold = completionOrder();
        $return = completionAccepted($sold, [$sold['lines']['first'] => 1]);

        $refused = Pr::thrown(fn () => completionReceive($return, $counts($sold['lines'])), InvalidSalesAttribute::class);

        expect($refused->attribute)->toBe($attribute)
            ->and(DB::table('sales.returns')->where('id', $return)->value('status'))->toBe('ACCEPTED');
    })->with([
        'a line missing' => [fn (array $l) => [$l['second'] => [1, 1]], 'lines'],
        'a line not of the return' => [fn (array $l) => [$l['first'] => [1, 1], $l['second'] => [1, 1]], 'lines'],
        'more than asked' => [fn (array $l) => [$l['first'] => [2, 0]], 'quantity'],
        'more back than came' => [fn (array $l) => [$l['first'] => [1, 2]], 'quantity'],
        'below none' => [fn (array $l) => [$l['first'] => [-1, 0]], 'quantity'],
    ]);

    it('receives only an accepted return, and completes only a received one', function () {
        $sold = completionOrder();
        Fx::actAsCustomer($sold['customer']);
        $waiting = app(RequestReturnHandler::class)->handle(new RequestReturn($sold['order'], 'DAMAGED', null, [['orderLineId' => $sold['lines']['first'], 'quantity' => 1]]));
        Fx::actAsStaff($sold['staff']);

        expect(fn () => completionReceive($waiting, [$sold['lines']['first'] => [1, 1]]))->toThrow(InvalidOrderChange::class)
            ->and(fn () => completionComplete($waiting))->toThrow(InvalidOrderChange::class);

        app(AcceptReturnHandler::class)->handle(new AcceptReturn($waiting));
        expect(fn () => completionComplete($waiting))->toThrow(InvalidOrderChange::class);

        completionReceive($waiting, [$sold['lines']['first'] => [1, 1]]);
        completionComplete($waiting);
        expect(fn () => completionComplete($waiting))->toThrow(InvalidOrderChange::class)
            ->and(fn () => completionReceive($waiting, [$sold['lines']['first'] => [1, 1]]))->toThrow(InvalidOrderChange::class);
    });
});

describe('what Sales tells Feedback', function () {
    it('names a delivered purchase, and whether any of it came back in a completed return', function () {
        $sold = completionOrder();
        $sales = app(SalesApi::class);
        $delivered = (string) completionOrderRow($sold['order'])?->delivered_at;

        $before = $sales->deliveredPurchase($sold['customer'], $sold['product']);
        $return = completionAccepted($sold, [$sold['lines']['second'] => 1]);
        completionReceive($return, [$sold['lines']['second'] => [1, 1]]);
        $notYet = $sales->deliveredPurchase($sold['customer'], $sold['product']);
        completionComplete($return);
        $after = $sales->deliveredPurchase($sold['customer'], $sold['product']);

        expect($before?->variantId)->toBeIn($sold['variants'])
            ->and($before?->deliveredAt->getTimestamp())->toBe(strtotime($delivered))
            ->and([$before?->returned, $notYet?->returned, $after?->returned])->toBe([false, false, true])
            ->and($sales->deliveredPurchase(Cx::customer(), $sold['product']))->toBeNull()
            ->and($sales->returnedProducts($return)?->productIds)->toBe([$sold['product']])
            ->and($sales->returnedProducts($return)?->customerId)->toBe($sold['customer'])
            ->and($sales->returnedProducts($return)?->storeId)->toBe(Fx::storeId('sa'));
    });

    it('names the latest delivery, and counts a return of another of the customer orders', function () {
        $sold = completionOrder();
        $return = completionAccepted($sold, [$sold['lines']['first'] => 1]);
        completionReceive($return, [$sold['lines']['first'] => [1, 1]]);
        completionComplete($return);
        // A later order of the second size, delivered an hour on.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        Fx::actAsCustomer($sold['customer']);
        DB::table('sales.cart_lines')->delete();
        Cx::add($sold['variants'][1]);
        $later = Cx::place(Cx::start($sold['address'])->id)->id;
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($later));
        DB::table('sales.orders')->where('id', $later)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($later, 'SMSA', null));
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($later));

        $purchase = app(SalesApi::class)->deliveredPurchase($sold['customer'], $sold['product']);

        expect($purchase?->variantId)->toBe($sold['variants'][1])
            ->and($purchase?->deliveredAt->getTimestamp())->toBe(strtotime((string) completionOrderRow($later)?->delivered_at))
            ->and($purchase?->returned)->toBeTrue();
    });

    it('never counts the gift, nor a return not completed or whose pieces never came', function () {
        $sold = completionOrder(withGift: true);
        $sales = app(SalesApi::class);
        $gift = completionAccepted($sold, [$sold['lines']['gift'] => 1]);
        completionReceive($gift, [$sold['lines']['gift'] => [1, 1]]);
        $none = completionAccepted($sold, [$sold['lines']['first'] => 1]);
        completionReceive($none, [$sold['lines']['first'] => [0, 0]]);

        expect($sales->returnedProducts($gift))->toBeNull();

        completionComplete($gift);
        completionComplete($none);

        expect($sales->returnedProducts($gift)?->productIds)->toBe([])
            ->and($sales->returnedProducts($none)?->productIds)->toBe([])
            ->and($sales->deliveredPurchase($sold['customer'], $sold['product'])?->returned)->toBeFalse();
    });
});

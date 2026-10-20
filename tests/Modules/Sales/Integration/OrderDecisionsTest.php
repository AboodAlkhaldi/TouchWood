<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Public\Events\HoldsShort;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrder;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrderHandler;
use Modules\Sales\Application\Command\ClearShortFlag\ClearShortFlag;
use Modules\Sales\Application\Command\ClearShortFlag\ClearShortFlagHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotCancellable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Infrastructure\Listener\FlagShortOrders;
use Modules\Sales\Public\Events\OrderCancelled;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Staff's decisions and cancelling (sales.md §1.4, §1.6, §1.11): approve or reject an order waiting; cancel
| one before it ships — staff with their words, the customer with a reason from the list; the points used
| back, what it holds freed, nothing due; OrderCancelled told, a rejection not; "stock was reduced"
| flagged on the open orders Inventory names, and cleared by staff. Each change locks the order, is kept
| in its history and, for staff, audited; an order of a store the reader does not cover is no order.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * An order placed by a customer who may order; acting as that customer afterwards.
 *
 * @return array{customer: string, address: string, variants: list<string>, product: string, order: string}
 */
function decidedOrder(bool $withPoints = false): array
{
    $ready = Cx::ready();

    if ($withPoints) {
        Pr::ceiling(100);
        Lx::programme();
        Lx::deliver($ready['customer'], 1_000_00);
    }

    $placed = Cx::place(Cx::start($ready['address'], usePoints: $withPoints)->id);

    return [...$ready, 'order' => $placed->id];
}

/**
 * Staff with these jobs in these stores, acting.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function decidedStaff(array $permissions, array $stores = ['sa']): string
{
    $staff = Fx::staffWith($permissions, $stores);
    Fx::actAsStaff($staff);

    return $staff;
}

function decidedRow(string $orderId): ?stdClass
{
    return DB::table('sales.orders')->where('id', $orderId)->first();
}

/** @return list<string> */
function decidedHistory(string $orderId): array
{
    return array_values(array_map(strval(...), DB::table('sales.order_history')->where('order_id', $orderId)->orderBy('id')->pluck('kind')->all()));
}

function decidedHoldOpen(string $orderId): bool
{
    return DB::table('inventory.hold_lines')->join('inventory.holds', 'holds.id', '=', 'hold_lines.hold_id')
        ->where('holds.order_id', $orderId)->where('hold_lines.ended', 'OPEN')->exists();
}

function decidedCancelledTold(string $orderId): int
{
    return DB::table('public.outbox_messages')->where('type', OrderCancelled::class)->where('payload->orderId', $orderId)->count();
}

describe('approving', function () {
    it('approves an order waiting, once, kept and audited', function () {
        ['order' => $order] = decidedOrder();
        $staff = decidedStaff([SalesPermissions::ORDER_APPROVE]);

        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));

        expect(decidedRow($order)?->status)->toBe('APPROVED')
            ->and(decidedRow($order)?->decided_by)->toBe($staff)
            ->and(decidedHistory($order))->toBe(['PLACED', 'APPROVED'])
            ->and(Fx::audits('sales.order.approved', $order))->toBe(1)
            ->and(decidedHoldOpen($order))->toBeTrue()
            ->and(fn () => app(ApproveOrderHandler::class)->handle(new ApproveOrder($order)))->toThrow(InvalidOrderChange::class);
    });

    it('answers an order of a store the reader does not cover as no order, and one with the job nowhere as not allowed', function () {
        ['order' => $order] = decidedOrder();

        decidedStaff([SalesPermissions::ORDER_APPROVE], ['eg']);
        expect(fn () => app(ApproveOrderHandler::class)->handle(new ApproveOrder($order)))->toThrow(OrderNotFound::class);

        decidedStaff([SalesPermissions::ORDER_VIEW]);
        expect(fn () => app(ApproveOrderHandler::class)->handle(new ApproveOrder($order)))->toThrow(Unauthorized::class);

        decidedStaff([SalesPermissions::ORDER_APPROVE]);
        expect(fn () => app(ApproveOrderHandler::class)->handle(new ApproveOrder(strtolower((string) Str::ulid()))))->toThrow(OrderNotFound::class)
            ->and(fn () => app(ApproveOrderHandler::class)->handle(new ApproveOrder('order')))->toThrow(OrderNotFound::class)
            ->and(decidedRow($order)?->status)->toBe('PENDING_APPROVAL');
    });

    it('lets no customer decide', function () {
        ['order' => $order] = decidedOrder();

        expect(fn () => app(ApproveOrderHandler::class)->handle(new ApproveOrder($order)))->toThrow(Unauthorized::class);
    });
});

describe('rejecting', function () {
    it('rejects an order waiting with a reason: nothing due, its stock freed, its points back, nothing told', function () {
        ['order' => $order, 'customer' => $customer] = decidedOrder(withPoints: true);
        $before = app(LoyaltyApi::class)->balance($customer, Fx::storeId('sa'))->points;
        expect(DB::table('sales.orders')->where('id', $order)->value('points_used'))->toBeGreaterThan(0);
        decidedStaff([SalesPermissions::ORDER_APPROVE]);

        app(RejectOrderHandler::class)->handle(new RejectOrder($order, '  Out of stock  '));

        $row = decidedRow($order);
        expect($row?->status)->toBe('REJECTED')
            ->and($row?->decision_reason)->toBe('Out of stock')
            ->and((int) $row?->due_minor)->toBe(0)
            ->and(decidedHoldOpen($order))->toBeFalse()
            ->and(app(LoyaltyApi::class)->balance($customer, Fx::storeId('sa'))->points)->toBe($before + (int) $row?->points_used)
            ->and(decidedCancelledTold($order))->toBe(0)
            ->and(decidedHistory($order))->toBe(['PLACED', 'REJECTED'])
            ->and(Fx::audits('sales.order.rejected', $order))->toBe(1);
    });

    it('refuses a rejection with no reason, or of an order already decided', function (Closure $first, string $reason, string $error) {
        ['order' => $order] = decidedOrder();
        decidedStaff([SalesPermissions::ORDER_APPROVE]);
        $first($order);

        expect(fn () => app(RejectOrderHandler::class)->handle(new RejectOrder($order, $reason)))->toThrow($error);
    })->with([
        'no reason' => [fn (string $order) => null, '   ', InvalidSalesAttribute::class],
        'too long' => [fn (string $order) => null, str_repeat('a', 501), InvalidSalesAttribute::class],
        'approved already' => [fn (string $order) => app(ApproveOrderHandler::class)->handle(new ApproveOrder($order)), 'Late', InvalidOrderChange::class],
    ]);
});

describe('cancelling', function () {
    it('lets staff cancel an order waiting or approved, with their words, telling of it', function (bool $approved) {
        ['order' => $order] = decidedOrder();
        $staff = decidedStaff([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_CANCEL]);

        if ($approved) {
            app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        }

        app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'The customer called'));

        $row = decidedRow($order);
        expect([$row?->status, $row?->cancel_reason, $row?->cancel_other, $row?->cancelled_by, (int) $row?->due_minor])
            ->toBe(['CANCELLED', 'BY_STAFF', 'The customer called', $staff, 0])
            ->and(decidedHoldOpen($order))->toBeFalse()
            ->and(decidedCancelledTold($order))->toBe(1)
            ->and(Fx::audits('sales.order.cancelled', $order))->toBe(1)
            ->and(fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Again')))->toThrow(NotCancellable::class);
    })->with([
        'waiting' => [false],
        'approved' => [true],
    ]);

    it('refuses to cancel what shipped, or was rejected', function (Closure $state) {
        ['order' => $order] = decidedOrder();
        decidedStaff([SalesPermissions::ORDER_CANCEL]);
        $state($order);

        expect(fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Too late')))->toThrow(NotCancellable::class)
            ->and(decidedCancelledTold($order))->toBe(0);
    })->with([
        'shipped' => [fn (string $order) => DB::table('sales.orders')->where('id', $order)->update([
            'status' => 'APPROVED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(),
            'shipment_status' => 'SHIPPED', 'shipped_at' => CarbonImmutable::now(), 'carrier' => 'SMSA',
        ])],
        'rejected' => [fn (string $order) => DB::table('sales.orders')->where('id', $order)->update([
            'status' => 'REJECTED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'decision_reason' => 'No', 'due_minor' => 0,
        ])],
    ]);

    it('lets the customer cancel their own order with a reason, saying whether money comes back', function (int $paid, bool $refund) {
        ['order' => $order] = decidedOrder();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => $paid]);

        $answer = app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'changed_mind'));

        $row = decidedRow($order);
        expect($answer)->toBe($refund)
            ->and([$row?->status, $row?->cancel_reason, $row?->cancel_other, $row?->cancelled_by])->toBe(['CANCELLED', 'CHANGED_MIND', null, null])
            ->and(decidedCancelledTold($order))->toBe(1)
            ->and(decidedHoldOpen($order))->toBeFalse()
            ->and(Fx::audits('sales.order.cancelled', $order))->toBe(0);
    })->with([
        'nothing paid' => [0, false],
        'paid' => [10_00, true],
    ]);

    it('takes Other only with words, and words only with Other', function (string $reason, ?string $other, ?string $error) {
        ['order' => $order] = decidedOrder();

        $call = fn () => app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, $reason, $other));

        $error === null ? expect($call())->toBeFalse()->and(decidedRow($order)?->cancel_other)->toBe(trim((string) $other)) : expect($call)->toThrow($error);
    })->with([
        'Other with words' => ['OTHER', ' Wrong colour ', null],
        'Other without words' => ['OTHER', null, InvalidSalesAttribute::class],
        'a listed reason with words' => ['TOO_SLOW', 'Slow', InvalidSalesAttribute::class],
        'by staff' => ['BY_STAFF', 'Me', InvalidSalesAttribute::class],
        'no such reason' => ['BORED', null, InvalidSalesAttribute::class],
    ]);

    it('answers another customer order as no order', function () {
        ['order' => $order] = decidedOrder();
        Fx::actAsCustomer(Cx::customer());

        expect(fn () => app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND')))->toThrow(OrderNotFound::class)
            ->and(decidedRow($order)?->status)->toBe('PENDING_APPROVAL');
    });
});

describe('stock was reduced', function () {
    it('flags the open unshipped orders Inventory names, once each', function () {
        ['order' => $open, 'variants' => $variants] = decidedOrder();
        $cancelled = Cx::place(Cx::start(Cx::ready()['address'])->id)->id;
        decidedStaff([SalesPermissions::ORDER_CANCEL]);
        app(CancelOrderHandler::class)->handle(new CancelOrder($cancelled, 'Gone'));
        $event = new HoldsShort((string) Str::uuid(), Fx::storeId('sa'), $variants[0], [$open, $cancelled, strtolower((string) Str::ulid())], CarbonImmutable::now());

        app(FlagShortOrders::class)->handle($event);
        app(FlagShortOrders::class)->handle($event);

        expect(decidedRow($open)?->short_of_stock_at)->not->toBeNull()
            ->and(decidedRow($cancelled)?->short_of_stock_at)->toBeNull()
            ->and(decidedHistory($open))->toBe(['PLACED', 'SHORT_FLAGGED']);
    });

    it('lets staff clear the flag, once', function () {
        ['order' => $order] = decidedOrder();
        DB::table('sales.orders')->where('id', $order)->update(['short_of_stock_at' => CarbonImmutable::now()]);
        decidedStaff([SalesPermissions::ORDER_EDIT]);

        app(ClearShortFlagHandler::class)->handle(new ClearShortFlag($order));
        app(ClearShortFlagHandler::class)->handle(new ClearShortFlag($order));

        expect(decidedRow($order)?->short_of_stock_at)->toBeNull()
            ->and(decidedHistory($order))->toBe(['PLACED', 'SHORT_CLEARED'])
            ->and(Fx::audits('sales.order.short_cleared', $order))->toBe(1);
    });
});

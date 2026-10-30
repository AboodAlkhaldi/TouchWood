<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RecordRefund\RecordRefund;
use Modules\Sales\Application\Command\RecordRefund\RecordRefundHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecord;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecordHandler;
use Modules\Sales\Application\Query\ListOrders\ListOrders;
use Modules\Sales\Application\Query\ListOrders\ListOrdersHandler;
use Modules\Sales\Application\Query\ListOrderStores\ListOrderStores;
use Modules\Sales\Application\Query\ListOrderStores\ListOrderStoresHandler;
use Modules\Sales\Application\Query\PrintOrder\PrintOrder;
use Modules\Sales\Application\Query\PrintOrder\PrintOrderHandler;
use Modules\Sales\Application\Query\Staff\HistoryRow;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Infrastructure\Listener\ForgetCustomer;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Enums\PaymentStatus;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Staff's reads of a store's orders (sales.md §3 ListOrders, ViewOrder, PrintOrder): the lists — all, unpaid,
| flagged short; one store or every store covered; newest first, 50 a page — and an order with its four
| machines, its customer by name, the money, the history and the edits, staff by name. Another store's
| order is none to a reader who does not cover it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** @return array{order: string, number: string, customer: string, address: string, variants: list<string>} */
function staffOrder(string $storeCode = 'sa'): array
{
    $ready = Cx::ready(storeCode: $storeCode);
    $placed = Cx::place(Cx::start($ready['address'], storeCode: $storeCode)->id, $storeCode);

    return [...$ready, 'order' => $placed->id, 'number' => $placed->number];
}

/**
 * @param  list<string>  $stores
 */
function staffReader(array $stores = ['sa']): string
{
    $staff = Fx::staffWith([SalesPermissions::ORDER_VIEW], $stores);
    Fx::actAsStaff($staff);

    return $staff;
}

it('lists every covered store orders newest first, and one store alone', function () {
    ['number' => $saudi, 'customer' => $customer] = staffOrder();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    ['number' => $egypt] = staffOrder('eg');
    staffReader(['*']);

    $all = app(ListOrdersHandler::class)->handle(new ListOrders);
    $one = app(ListOrdersHandler::class)->handle(new ListOrders(Fx::storeId('sa')));

    expect(array_column($all->orders, 'number'))->toBe([$egypt, $saudi])
        ->and(array_column($one->orders, 'number'))->toBe([$saudi])
        ->and($one->orders[0]->customer->name)->toBe('Sara Ali')
        ->and([$one->orders[0]->status, $one->orders[0]->payment, $one->orders[0]->customerStatus])->toBe(['PENDING_APPROVAL', PaymentStatus::Unpaid, CustomerOrderStatus::New])
        ->and($one->orders[0]->exponent)->toBe(2)
        ->and($customer)->toBe($one->orders[0]->customer->customerId);
});

it('lists only the stores the reader covers, and refuses a store they do not', function () {
    staffOrder();
    staffOrder('eg');
    staffReader(['eg']);

    expect(array_column(app(ListOrdersHandler::class)->handle(new ListOrders)->orders, 'storeId'))->toBe([Fx::storeId('eg')])
        ->and(fn () => app(ListOrdersHandler::class)->handle(new ListOrders(Fx::storeId('sa'))))->toThrow(Unauthorized::class);
});

it('filters the unpaid and the flagged short', function () {
    ['order' => $paid] = staffOrder();
    ['order' => $short] = staffOrder();
    ['order' => $partly] = staffOrder();
    ['order' => $refundDue] = staffOrder();
    ['order' => $cancelled] = staffOrder();
    DB::table('sales.orders')->where('id', $paid)->update(['paid_minor' => DB::raw('due_minor')]);
    DB::table('sales.orders')->where('id', $short)->update(['short_of_stock_at' => CarbonImmutable::now()]);
    DB::table('sales.orders')->where('id', $partly)->update(['paid_minor' => 1]);
    DB::table('sales.orders')->where('id', $refundDue)->update(['paid_minor' => DB::raw('due_minor + 100')]);
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE], ['sa']));
    app(RejectOrderHandler::class)->handle(new RejectOrder($cancelled, 'Out of the delivery area'));
    staffReader();

    // Waiting for approval, an order holds less than its total is due: listed as unpaid.
    expect(array_column(app(ListOrdersHandler::class)->handle(new ListOrders(filter: 'UNPAID'))->orders, 'orderId'))->toEqualCanonicalizing([$short, $partly])
        ->and(array_column(app(ListOrdersHandler::class)->handle(new ListOrders(filter: 'SHORT'))->orders, 'orderId'))->toBe([$short])
        ->and(fn () => app(ListOrdersHandler::class)->handle(new ListOrders(filter: 'OLD')))->toThrow(InvalidSalesAttribute::class);
});

it('pages 50 at a time, going on after the last order id', function () {
    ['address' => $address, 'variants' => $variants, 'customer' => $customer] = staffOrder();

    foreach (range(1, 50) as $n) {
        // Under the cap of orders waiting.
        Cx::approveWaiting($customer);
        Cx::add($variants[1]);
        Cx::place(Cx::start($address)->id);
    }

    staffReader();
    $first = app(ListOrdersHandler::class)->handle(new ListOrders);
    $second = app(ListOrdersHandler::class)->handle(new ListOrders(after: $first->next));

    expect([count($first->orders), count($second->orders), $second->next])->toBe([50, 1, null])
        ->and(array_intersect(array_column($first->orders, 'orderId'), array_column($second->orders, 'orderId')))->toBe([])
        ->and(app(ListOrdersHandler::class)->handle(new ListOrders(after: 'not-an-id'))->orders)->toBe([]);
});

it('shows staff an order: the customer by name and the contact, the money, the history and the decision, staff by name', function () {
    ['order' => $order, 'number' => $number] = staffOrder();
    $staff = Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::PAYMENT_RECORD, SalesPermissions::ORDER_VIEW], ['sa']);
    Fx::actAsStaff($staff);
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'TX-1', null, CarbonImmutable::now()->format('Y-m-d'), (string) Str::uuid()));

    $view = app(ViewOrderHandler::class)->handle(new ViewOrder($order));
    $due = (int) DB::table('sales.orders')->where('id', $order)->value('due_minor');

    expect([$view->number, $view->status, $view->payment, $view->customer->name, $view->contactName])->toBe([$number, 'APPROVED', PaymentStatus::Paid, 'Sara Ali', 'Sara Ali'])
        ->and($view->contactPhone)->not->toBe('')
        ->and($view->decidedBy)->toBe('Staff Member')
        ->and(array_map(fn ($row): array => [$row->kind, $row->amount->minorUnits, $row->reference], $view->money))->toBe([['PAYMENT', $due, 'TX-1']])
        ->and(array_map(fn (HistoryRow $row): string => $row->kind, $view->history))->toBe(['PLACED', 'APPROVED', 'PAYMENT_RECORDED'])
        ->and($view->history[1]->by)->toBe($view->decidedBy)
        ->and(count($view->lines))->toBe(2)
        ->and($view->exponent)->toBe(2);
});

it('shows a rejection with staff words, and prints an order', function () {
    ['order' => $order, 'number' => $number] = staffOrder();
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_VIEW], ['sa']));
    app(RejectOrderHandler::class)->handle(new RejectOrder($order, 'Out of the delivery area'));

    $view = app(ViewOrderHandler::class)->handle(new ViewOrder($order));
    $print = app(PrintOrderHandler::class)->handle(new PrintOrder($order));

    expect([$view->status, $view->customerStatus, $view->decisionReason])->toBe(['REJECTED', CustomerOrderStatus::Cancelled, 'Out of the delivery area'])
        ->and([$print->number, $print->contactName, count($print->lines), $print->total->minorUnits, $print->wayToPay])->toBe([$number, 'Sara Ali', 2, $view->total->minorUnits, $view->wayToPay])
        ->and($print->wayToPay)->toBeInstanceOf(WayToPay::class)
        ->and($print->vatRateBasisPoints)->toBeGreaterThanOrEqual(0);
});

it('answers an order of a store the reader does not cover as none, and a customer as not allowed', function () {
    ['order' => $order, 'customer' => $customer] = staffOrder();
    staffReader(['eg']);

    expect(fn () => app(ViewOrderHandler::class)->handle(new ViewOrder($order)))->toThrow(OrderNotFound::class)
        ->and(fn () => app(PrintOrderHandler::class)->handle(new PrintOrder($order)))->toThrow(OrderNotFound::class);

    Fx::actAsCustomer($customer);
    expect(fn () => app(ListOrdersHandler::class)->handle(new ListOrders))->toThrow(Unauthorized::class)
        ->and(fn () => app(ViewOrderHandler::class)->handle(new ViewOrder($order)))->toThrow(Unauthorized::class);
});

it('names an anonymized customer as deleted, the email gone from the order', function () {
    ['order' => $order, 'customer' => $customer] = staffOrder();
    DB::table('access.customers')->where('id', $customer)->update(['anonymized_at' => CarbonImmutable::now()]);
    app(ForgetCustomer::class)->handle(new CustomerAnonymized((string) Str::uuid(), $customer, CarbonImmutable::now()));
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa']));

    $view = app(ViewOrderHandler::class)->handle(new ViewOrder($order));

    // The order keeps its snapshot of name and phone, a financial record (§1.12).
    expect([$view->customer->name, $view->customer->deleted, $view->contactEmail, $view->contactName])->toBe([null, true, null, 'Sara Ali']);
});

it('shows the contact email only to staff who may view the customer', function () {
    ['order' => $order] = staffOrder();
    staffReader();
    $hidden = app(ViewOrderHandler::class)->handle(new ViewOrder($order))->contactEmail;
    // Viewing customers only in another store: not this customer, whose home store is Saudi Arabia.
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa', 'eg'], exceptions: [AccessPermissions::CUSTOMER_VIEW => ['eg']]));
    $hidden2 = app(ViewOrderHandler::class)->handle(new ViewOrder($order))->contactEmail;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa']));

    expect([$hidden, $hidden2])->toBe([null, null])
        ->and(app(ViewOrderHandler::class)->handle(new ViewOrder($order))->contactEmail)->toBe(DB::table('sales.orders')->where('id', $order)->value('customer_email'))
        ->and(app(ViewOrderHandler::class)->handle(new ViewOrder($order))->contactEmail)->not->toBeNull();
});

it('lists the payments and refunds in the order recorded, a voided one with who voided it and why', function () {
    ['order' => $order] = staffOrder();
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::PAYMENT_RECORD, SalesPermissions::ORDER_VIEW], ['sa']));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    $today = CarbonImmutable::now()->format('Y-m-d');
    $due = (int) DB::table('sales.orders')->where('id', $order)->value('due_minor');
    app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'TX-1', null, $today, (string) Str::uuid()));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    // An edit raised the total by 50.00, paid as one payment more.
    DB::table('sales.orders')->where('id', $order)->update(['due_minor' => DB::raw('due_minor + 5000'), 'order_total_minor' => DB::raw('order_total_minor + 5000')]);
    $twice = app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'TX-2', null, $today, (string) Str::uuid()));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    app(RecordRefundHandler::class)->handle(new RecordRefund($order, '30.00', 'OTHER', null, 'Cash at the counter', $today, (string) Str::uuid()));
    app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', (string) $twice, 'Typed twice'));

    $money = app(ViewOrderHandler::class)->handle(new ViewOrder($order))->money;

    expect(array_map(fn ($row): array => [$row->kind, $row->amount->minorUnits, $row->way, $row->note, $row->happenedOn], $money))
        ->toBe([['PAYMENT', $due, 'BANK_TRANSFER', null, $today], ['PAYMENT', 50_00, 'BANK_TRANSFER', null, $today], ['REFUND', 30_00, 'OTHER', 'Cash at the counter', $today]])
        ->and([$money[0]->voidedBy, $money[1]->voidedBy, $money[1]->voidReason, $money[2]->voidedBy])->toBe([null, 'Staff Member', 'Typed twice', null])
        ->and($money[1]->voidedAt)->not->toBeNull();
});

it('lists an off store to its own staff while it holds an unfinished order, and to a Super Admin always', function () {
    ['order' => $order] = staffOrder('eg');
    $egypt = Fx::storeId('eg');
    $staff = Fx::staffWith([SalesPermissions::ORDER_VIEW, SalesPermissions::ORDER_APPROVE], ['sa', 'eg']);
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    Fx::actAsStaff($staff);
    $stores = fn (): array => array_column(app(ListOrderStoresHandler::class)->handle(new ListOrderStores), 'storeId');

    // Waiting, though wholly paid: unfinished by its state alone.
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
    $open = [$stores(), array_column(app(ListOrdersHandler::class)->handle(new ListOrders)->orders, 'orderId'), app(ListOrdersHandler::class)->handle(new ListOrders($egypt))->orders[0]->orderId];
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 0]);
    app(RejectOrderHandler::class)->handle(new RejectOrder($order, 'Out of the delivery area'));
    // Rejected but holding money: a refund due keeps it unfinished; refunded, it is finished.
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 100]);
    $refundDue = $stores();
    DB::table('sales.orders')->where('id', $order)->update(['refunded_minor' => 100]);

    expect($open)->toBe([[Fx::storeId('sa'), $egypt], [$order], $order])
        ->and($refundDue)->toBe([Fx::storeId('sa'), $egypt])
        ->and($stores())->toBe([Fx::storeId('sa')])
        ->and(app(ListOrdersHandler::class)->handle(new ListOrders)->orders)->toBe([])
        ->and(fn () => app(ListOrdersHandler::class)->handle(new ListOrders($egypt)))->toThrow(Unauthorized::class);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect($stores())->toContain($egypt)
        ->and(array_column(app(ListOrdersHandler::class)->handle(new ListOrders($egypt))->orders, 'orderId'))->toBe([$order]);
});

it('reads a list and an order in the same number of queries however many rows', function () {
    ['order' => $order, 'address' => $address, 'variants' => $variants, 'customer' => $customer] = staffOrder();
    $staff = Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::PAYMENT_RECORD, SalesPermissions::ORDER_VIEW], ['sa']);
    Fx::actAsStaff($staff);
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    $count = function (callable $read): int {
        $read();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $list = fn () => app(ListOrdersHandler::class)->handle(new ListOrders);
    $view = fn () => app(ViewOrderHandler::class)->handle(new ViewOrder($order));
    // Each payment the whole balance: a raised total (an edit's) owes one payment more.
    $pay = function () use ($order): void {
        app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', null, null, CarbonImmutable::now()->format('Y-m-d'), (string) Str::uuid()));
        DB::table('sales.orders')->where('id', $order)->update(['due_minor' => DB::raw('due_minor + 100'), 'order_total_minor' => DB::raw('order_total_minor + 100')]);
    };
    $pay();
    [$oneRow, $oneMoney] = [$count($list), $count($view)];

    Fx::actAsCustomer($customer);

    foreach (range(1, 3) as $n) {
        Cx::add($variants[1]);
        Cx::place(Cx::start($address)->id);
    }

    Fx::actAsStaff($staff);
    $pay();
    $pay();

    // Measured 2026-10-25, warm: the list 2 (the orders, the customers; Platform's stores cached, Sales's
    // off-store check one more only while a store is off); the order 11 (its row, the customer, money, history,
    // edits, ticks, documents, returns, staff names, the lines' sizes) - targets, not caps (owner, 2026-10-19).
    // Measured 2026-10-30: one more each, 3 and 12 - the SMS countries list, read once with the customers
    // (access.md amendment 87).
    expect([$count($list), $count($view)])->toBe([$oneRow, $oneMoney])
        ->and([$oneRow, $oneMoney])->toBe([3, 12]);
});

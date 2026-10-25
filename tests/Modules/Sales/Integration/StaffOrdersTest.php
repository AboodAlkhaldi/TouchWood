<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\Query\ListOrders\ListOrders;
use Modules\Sales\Application\Query\ListOrders\ListOrdersHandler;
use Modules\Sales\Application\Query\PrintOrder\PrintOrder;
use Modules\Sales\Application\Query\PrintOrder\PrintOrderHandler;
use Modules\Sales\Application\Query\Staff\HistoryRow;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Enums\PaymentStatus;
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
    DB::table('sales.orders')->where('id', $paid)->update(['paid_minor' => DB::raw('due_minor')]);
    DB::table('sales.orders')->where('id', $short)->update(['short_of_stock_at' => CarbonImmutable::now()]);
    staffReader();

    expect(array_column(app(ListOrdersHandler::class)->handle(new ListOrders(filter: 'UNPAID'))->orders, 'orderId'))->toBe([$short])
        ->and(array_column(app(ListOrdersHandler::class)->handle(new ListOrders(filter: 'SHORT'))->orders, 'orderId'))->toBe([$short])
        ->and(fn () => app(ListOrdersHandler::class)->handle(new ListOrders(filter: 'OLD')))->toThrow(InvalidSalesAttribute::class);
});

it('pages 50 at a time, going on after the last order id', function () {
    ['address' => $address, 'variants' => $variants] = staffOrder();

    foreach (range(1, 50) as $n) {
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
    app(RecordPaymentHandler::class)->handle(new RecordPayment($order, '100.00', 'BANK_TRANSFER', 'TX-1', null, CarbonImmutable::now()->format('Y-m-d'), (string) Str::uuid()));

    $view = app(ViewOrderHandler::class)->handle(new ViewOrder($order));

    expect([$view->number, $view->status, $view->payment, $view->customer->name, $view->contactName])->toBe([$number, 'APPROVED', PaymentStatus::PartlyPaid, 'Sara Ali', 'Sara Ali'])
        ->and($view->contactPhone)->not->toBe('')
        ->and($view->decidedBy)->not->toBeNull()
        ->and(array_map(fn ($row): array => [$row->kind, $row->amount->minorUnits, $row->reference], $view->money))->toBe([['PAYMENT', 100_00, 'TX-1']])
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
        ->and([$print->number, $print->contactName, count($print->lines), $print->total->minorUnits])->toBe([$number, 'Sara Ali', 2, $view->total->minorUnits])
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

it('names an anonymized customer as deleted', function () {
    ['order' => $order, 'customer' => $customer] = staffOrder();
    DB::table('access.customers')->where('id', $customer)->update(['anonymized_at' => CarbonImmutable::now()]);
    staffReader();

    $view = app(ViewOrderHandler::class)->handle(new ViewOrder($order));

    expect([$view->customer->name, $view->customer->deleted])->toBe([null, true]);
});

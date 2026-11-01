<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\B2B\Public\Dto\BankAccountDto;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturn;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturnHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocument;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocumentHandler;
use Modules\Sales\Application\Command\WithdrawReturn\WithdrawReturn;
use Modules\Sales\Application\Command\WithdrawReturn\WithdrawReturnHandler;
use Modules\Sales\Application\Query\ListOwnOrders\ListOwnOrders;
use Modules\Sales\Application\Query\ListOwnOrders\ListOwnOrdersHandler;
use Modules\Sales\Application\Query\Own\OrderLineView;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrder;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrderHandler;
use Modules\Sales\Application\Query\ViewOwnReturn\ViewOwnReturn;
use Modules\Sales\Application\Query\ViewOwnReturn\ViewOwnReturnHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Enums\PaymentStatus;
use Modules\Sales\Public\Enums\ReturnReason;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A customer's own orders and returns (sales.md §3 ListOwnOrders, ViewOwnOrder, ViewOwnReturn): every store's,
| newest first, each known by its number; the order with its lines as sold, its totals, the status they see,
| and what they may still do; the return with its lines and staff's words. Another customer's are none.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

afterEach(fn () => File::deleteDirectory(Bx::uploads()));

/** @return array{customer: string, address: string, variants: list<string>, order: string, number: string} */
function ownOrder(): array
{
    $ready = Cx::ready();
    $placed = Cx::place(Cx::start($ready['address'])->id);

    return [...$ready, 'order' => $placed->id, 'number' => $placed->number];
}

/** Approved, paid, shipped and delivered by staff; acting as the customer again. */
function ownDeliver(string $order, string $customer): void
{
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: 'T-1'));
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    Fx::actAsCustomer($customer);
}

it('lists the customer orders newest first, 20 a page, each by its number with the status they see', function () {
    ['customer' => $customer, 'address' => $address, 'variants' => $variants] = Cx::ready();
    // The first order reaches a gift level: its gift is not a piece bought.
    Pr::giftLevel($variants[1], 100_00);
    $first = Cx::place(Cx::start($address)->id)->number;
    $numbers = [$first];

    foreach (range(1, 20) as $n) {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        // Under the cap of orders waiting; the newest stays waiting (New).
        Cx::approveWaiting($customer);
        Cx::add($variants[1]);
        $numbers[] = Cx::place(Cx::start($address)->id)->number;
    }

    $handler = app(ListOwnOrdersHandler::class);
    $page = $handler->handle(new ListOwnOrders);
    $more = $handler->handle(new ListOwnOrders($page->next));

    expect(array_column([...$page->orders, ...$more->orders], 'number'))->toBe(array_reverse($numbers))
        ->and([count($page->orders), $page->next, $more->next])->toBe([20, $numbers[1], null])
        ->and($page->orders[0]->status)->toBe(CustomerOrderStatus::New)
        ->and(DB::table('sales.order_lines')->where('is_gift', true)->count())->toBeGreaterThan(0)
        ->and($more->orders[0]->pieces)->toBe(3)
        ->and($more->orders[0]->total->minorUnits)->toBe((int) DB::table('sales.orders')->where('number', $first)->value('order_total_minor'))
        ->and($more->orders[0]->exponent)->toBe(2)
        ->and($customer)->toBe(DB::table('sales.orders')->where('number', $first)->value('customer_id'));
});

it('shows the customer their order: its lines as sold, its totals, its statuses and what they may do', function () {
    ['customer' => $customer, 'number' => $number, 'order' => $order] = ownOrder();

    $waiting = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder(strtolower($number)));
    // Shipped, not yet delivered: no longer cancellable.
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: 'T-1'));
    Fx::actAsCustomer($customer);
    $shipped = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_FULFIL], ['sa']));
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    Fx::actAsCustomer($customer);
    $delivered = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));

    expect([$waiting->number, $waiting->status, $waiting->payment, $waiting->mayCancel, $waiting->mayReturn])->toBe([$number, CustomerOrderStatus::New, PaymentStatus::Unpaid, true, false])
        ->and(array_map(fn (OrderLineView $line): array => [$line->quantity, $line->unit->minorUnits, $line->returnable], $waiting->lines))->toBe([[2, 100_00, 0], [1, 50_00, 0]])
        ->and([$shipped->status, $shipped->mayCancel, $shipped->mayReturn])->toBe([CustomerOrderStatus::Shipped, false, false])
        ->and($waiting->total->minorUnits)->toBe($waiting->goodsTotal->minorUnits + $waiting->shipping->minorUnits + $waiting->vat->minorUnits)
        ->and([$delivered->status, $delivered->payment, $delivered->mayCancel, $delivered->mayReturn, $delivered->carrier])->toBe([CustomerOrderStatus::Delivered, PaymentStatus::Paid, false, true, 'SMSA'])
        ->and(array_map(fn (OrderLineView $line): int => $line->returnable, $delivered->lines))->toBe([2, 1])
        ->and($delivered->returnDeadline)->not->toBeNull()
        ->and($delivered->address)->toHaveKey('recipientName');
});

it('counts the pieces already asked back, and closes the window at its deadline', function () {
    ['customer' => $customer, 'number' => $number, 'order' => $order] = ownOrder();
    ownDeliver($order, $customer);
    $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->where('quantity', 2)->value('id');
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));

    $view = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));
    CarbonImmutable::setTestNow(CarbonImmutable::parse((string) DB::table('sales.orders')->where('id', $order)->value('return_deadline_at'))->addSecond());
    $late = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));

    expect(array_map(fn (OrderLineView $line): int => $line->returnable, $view->lines))->toBe([1, 1])
        ->and(array_map(fn ($row): array => [$row->returnId, $row->status, $row->staffReason], $view->returns))->toBe([[$return, 'REQUESTED', null]])
        ->and([$late->mayReturn, array_map(fn (OrderLineView $line): int => $line->returnable, $late->lines)])->toBe([false, [0, 0]]);
});

it('shows the customer their return, with staff words when refused', function () {
    ['customer' => $customer, 'order' => $order, 'number' => $number] = ownOrder();
    ownDeliver($order, $customer);
    $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->where('quantity', 2)->value('id');
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'OTHER', 'Not the colour shown', [['orderLineId' => $line, 'quantity' => 2]]));
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['sa']));
    app(RefuseReturnHandler::class)->handle(new RefuseReturn($return, 'Opened and used'));
    Fx::actAsCustomer($customer);

    $view = app(ViewOwnReturnHandler::class)->handle(new ViewOwnReturn(strtoupper($return)));
    // The order's page lists it with the same words (sales.md §4.1 S8, §4.2 B16).
    $rows = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->returns;

    expect([$view->orderNumber, $view->status, $view->reason, $view->reasonOther, $view->staffReason])->toBe([$number, 'REFUSED', ReturnReason::Other, 'Not the colour shown', 'Opened and used'])
        ->and([$view->lines[0]->asked, $view->lines[0]->received, $view->photos])->toBe([2, null, 0])
        ->and($view->decidedAt)->not->toBeNull()
        ->and($view->refundDue)->toBeNull()
        ->and(array_map(fn ($row): array => [$row->returnId, $row->status, $row->staffReason], $rows))->toBe([[$return, 'REFUSED', 'Opened and used']]);
});

it('answers another customer order and return as none, and staff as not allowed', function () {
    ['customer' => $customer, 'order' => $order, 'number' => $number] = ownOrder();
    ownDeliver($order, $customer);
    $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->value('id');
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['sa']));
    app(AcceptReturnHandler::class)->handle(new AcceptReturn($return));
    Fx::actAsCustomer(Cx::customer());

    expect(fn () => app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number)))->toThrow(OrderNotFound::class)
        ->and(fn () => app(ViewOwnReturnHandler::class)->handle(new ViewOwnReturn($return)))->toThrow(ReturnNotFound::class)
        ->and(fn () => app(ViewOwnReturnHandler::class)->handle(new ViewOwnReturn((string) Str::ulid())))->toThrow(ReturnNotFound::class)
        ->and(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders)->orders)->toBe([])
        ->and(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders($number))->orders)->toBe([])
        ->and(fn () => app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number)))->toThrow(OrderNotFound::class)
        ->and(fn () => app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder(str_repeat('X', 25))))->toThrow(OrderNotFound::class);

    Fx::actAsStaff(Fx::staff());
    expect(fn () => app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders))->toThrow(Unauthorized::class);
});

it('never goes on from another customer order number', function () {
    ['customer' => $sara, 'address' => $address, 'variants' => $variants] = ownOrder();
    Cx::add($variants[1]);
    Cx::place(Cx::start($address)->id);
    ['number' => $omars] = ownOrder();
    Fx::actAsCustomer($sara);

    expect(count(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders)->orders))->toBe(2)
        ->and(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders($omars))->orders)->toBe([]);
});

it('pages two orders placed in the same second, one after the other', function () {
    ['customer' => $customer, 'address' => $address, 'variants' => $variants, 'number' => $first] = ownOrder();
    Cx::add($variants[1]);
    $second = Cx::place(Cx::start($address)->id)->number;
    DB::table('sales.orders')->update(['placed_at' => '2026-10-20 10:00:00+00']);
    $ids = DB::table('sales.orders')->orderByDesc('id')->pluck('number')->all();

    $page = app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders);

    expect(array_column($page->orders, 'number'))->toBe($ids)
        ->and(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders($ids[0]))->orders[0]->number ?? null)->toBe($ids[1])
        ->and([$first, $second])->toEqualCanonicalizing($ids)
        ->and($customer)->not->toBeEmpty();
});

it('offers cancelling while approved and unshipped, carrying the order id to act on it', function () {
    ['customer' => $customer, 'number' => $number, 'order' => $order] = ownOrder();
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE], ['sa']));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    Fx::actAsCustomer($customer);

    $view = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));

    expect([$view->status, $view->mayCancel, $view->orderId, $view->wayToPay, $view->exponent])->toBe([CustomerOrderStatus::Processing, true, $order, WayToPay::StaffContact, 2]);
});

it('offers a transfer document only while a bank-transfer order holds less than is due, under three', function () {
    $b2b = Mockery::mock(B2BApi::class);
    $b2b->shouldReceive('isApproved')->andReturn(true);
    $b2b->shouldReceive('bankAccount')->andReturn(new BankAccountDto('SA00 0000', 'Bank', 'TouchWood'));
    app()->instance(B2BApi::class, $b2b);
    $ready = Cx::ready('company');
    $placed = Cx::place(Cx::start($ready['address'], 'BANK_TRANSFER')->id);
    $view = fn () => app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($placed->number));
    $may = [$view()->mayUploadTransferDocument];

    Storage::fake('local', ['serve' => true]);

    foreach (range(1, 3) as $n) {
        app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($placed->id, Bx::pdf(), 'transfer.pdf'));
    }

    $may[] = $view()->mayUploadTransferDocument;
    DB::table('sales.transfer_documents')->where('order_id', $placed->id)->delete();
    DB::table('sales.orders')->where('id', $placed->id)->update(['paid_minor' => DB::raw('due_minor')]);
    $may[] = $view()->mayUploadTransferDocument;

    expect($may)->toBe([true, false, false])
        ->and($view()->transferDocuments)->toBe(0);
});

it('gives a withdrawn return its moment', function () {
    ['customer' => $customer, 'order' => $order] = ownOrder();
    ownDeliver($order, $customer);
    $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->value('id');
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));
    app(WithdrawReturnHandler::class)->handle(new WithdrawReturn($return));

    $view = app(ViewOwnReturnHandler::class)->handle(new ViewOwnReturn($return));

    expect([$view->status, $view->reason, $view->closedAt])->toBe(['WITHDRAWN', ReturnReason::Damaged, null])
        ->and($view->withdrawnAt)->not->toBeNull();
});

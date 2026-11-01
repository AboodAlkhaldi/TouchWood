<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\B2B\Public\Dto\BankAccountDto;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocument;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocumentHandler;
use Modules\Sales\Application\Query\ListCarriers\ListCarriers;
use Modules\Sales\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Sales\Application\Query\ListOrders\ListOrders;
use Modules\Sales\Application\Query\ListOrders\ListOrdersHandler;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Presentation\Http\Resource\OrdersCustomerSection;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| What the Sales screens asked of staff's order reads (sales.md §3.2; the screens' B2–B5, B9): the lists by
| waiting, to ship, shipped and transfers to check; one box searching a number, a name or a phone; one
| customer's orders; the carriers a store shipped with; a customer's latest orders on the customer page; and
| which sizes of a wired order may be ticked.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local', ['serve' => true]);
});

afterEach(fn () => File::deleteDirectory(Bx::uploads()));

/** @return array{order: string, number: string, customer: string, address: string, variants: list<string>} */
function solOrder(bool $wired = false): array
{
    $ready = Cx::ready();

    // Wired after the stock was counted, before the order is placed (its hold remembers).
    if ($wired) {
        Ix::wired();
        // The first size's normal sales made to depend on its stock (a wired store's switch): it counts.
        app(InventoryApi::class)->makeStockDependent(StoreId::fromString(Fx::storeId('sa')), [$ready['variants'][0]]);
    }

    $placed = Cx::place(Cx::start($ready['address'])->id);

    return [...$ready, 'order' => $placed->id, 'number' => $placed->number];
}

function solStaff(): string
{
    $staff = Fx::staffWith([SalesPermissions::ORDER_VIEW, SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']);
    Fx::actAsStaff($staff);

    return $staff;
}

/** @return list<string> */
function solIds(ListOrders $query): array
{
    return array_column(app(ListOrdersHandler::class)->handle($query)->orders, 'orderId');
}

function solShip(string $order, string $carrier): void
{
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: $carrier, trackingNumber: null));
}

it('lists the orders waiting, to ship and shipped, and the bank transfers to check', function () {
    $b2b = Mockery::mock(B2BApi::class);
    $b2b->shouldReceive('isApproved')->andReturn(true);
    $b2b->shouldReceive('bankAccount')->andReturn(new BankAccountDto('SA00 0000', 'Bank', 'TouchWood'));
    app()->instance(B2BApi::class, $b2b);
    ['order' => $waiting] = solOrder();
    ['order' => $toShip] = solOrder();
    ['order' => $shipped] = solOrder();
    $company = Cx::ready('company');
    $transfer = Cx::place(Cx::start($company['address'], 'BANK_TRANSFER')->id)->id;
    Cx::add($company['variants'][0]);
    $noDocument = Cx::place(Cx::start($company['address'], 'BANK_TRANSFER')->id, 'sa')->id;
    app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($transfer, Bx::pdf(), 'transfer.pdf'));
    // A document on an order paid another way is no transfer to check.
    DB::table('sales.transfer_documents')->insert([...(array) DB::table('sales.transfer_documents')->where('order_id', $transfer)->first(), 'order_id' => $waiting]);
    solStaff();
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($toShip));
    solShip($shipped, 'SMSA');

    $list = fn (string $filter): array => solIds(new ListOrders(filter: $filter));

    expect($list('WAITING_APPROVAL'))->toEqualCanonicalizing([$waiting, $transfer, $noDocument])
        ->and($list('TO_SHIP'))->toBe([$toShip])
        ->and($list('SHIPPED'))->toBe([$shipped])
        ->and($list('TRANSFERS_TO_CHECK'))->toBe([$transfer])
        ->and(fn () => $list('LOST'))->toThrow(InvalidSalesAttribute::class);

    // Paid in full, a transfer has nothing left to check; delivered, an order is no longer shipped.
    DB::table('sales.orders')->where('id', $transfer)->update(['paid_minor' => DB::raw('due_minor')]);
    DB::table('sales.orders')->where('id', $shipped)->update(['shipment_status' => 'DELIVERED', 'delivered_at' => now(), 'status' => 'COMPLETED', 'return_deadline_at' => now()->addDays(14)]);

    expect($list('TRANSFERS_TO_CHECK'))->toBe([])
        ->and($list('SHIPPED'))->toBe([]);
});

it('searches an order number, a customer name or a phone in one box, with the store and the filter', function () {
    ['order' => $first, 'number' => $number] = solOrder();
    ['order' => $second] = solOrder();
    DB::table('sales.orders')->where('id', $first)->update(['customer_name' => 'Sara Ali', 'customer_phone' => '+966 50 123 4567']);
    DB::table('sales.orders')->where('id', $second)->update(['customer_name' => 'Omar Hadi', 'customer_phone' => '+966559998877']);
    solStaff();
    $search = fn (?string $q, string $filter = 'ALL'): array => solIds(new ListOrders(storeId: Fx::storeId('sa'), filter: $filter, q: $q));

    expect($search(strtolower($number)))->toBe([$first])
        ->and($search('sara'))->toBe([$first])
        ->and($search('OMAR h'))->toBe([$second])
        // Digits only for a phone, spaces and + ignored, Arabic-Indic digits read as Latin.
        ->and($search('501234'))->toBe([$first])
        ->and($search('٥٥٩٩٩'))->toBe([$second])
        ->and($search('%%'))->toBe([])
        ->and($search('__'))->toBe([])
        // A phone typed the local way, its leading zero dropped; letters with digits search no phone.
        ->and($search('050 123 4567'))->toBe([$first])
        ->and($search('a5'))->toBe([])
        // Shorter than 2: no search at all.
        ->and($search('s'))->toEqualCanonicalizing([$first, $second])
        ->and($search('sara', 'SHIPPED'))->toBe([])
        ->and(fn () => $search(str_repeat('a', 101)))->toThrow(InvalidSalesAttribute::class);
});

it('lists one customer\'s orders alone', function () {
    ['order' => $first, 'customer' => $customer] = solOrder();
    solOrder();
    solStaff();

    expect(solIds(new ListOrders(customerId: strtoupper($customer))))->toBe([$first])
        ->and(solIds(new ListOrders(customerId: 'nobody')))->toBe([]);
});

it('lists the store\'s carriers from Shipping to staff who ship (§9.5 #3)', function () {
    solOrder();
    solStaff();

    expect(array_map(static fn ($carrier): string => $carrier->nameEn, app(ListCarriersHandler::class)->handle(new ListCarriers(Fx::storeId('sa')))))->toBe([Cx::CARRIER])
        ->and(fn () => app(ListCarriersHandler::class)->handle(new ListCarriers(Fx::storeId('eg'))))->toThrow(Unauthorized::class);

    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
    expect(fn () => app(ListCarriersHandler::class)->handle(new ListCarriers(Fx::storeId('sa'))))->toThrow(Unauthorized::class)
        ->and(fn () => app(ListCarriersHandler::class)->handle(new ListCarriers('not-a-store')))->toThrow(InvalidSalesAttribute::class);
});

it('shows a customer\'s latest orders on the staff customer page, none to a reader without the job', function () {
    ['order' => $first, 'customer' => $customer, 'address' => $address, 'variants' => $variants] = solOrder();
    ['order' => $someoneElses] = solOrder();
    Fx::actAsCustomer($customer);

    foreach (range(1, 10) as $n) {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        // Under the cap of orders waiting.
        Cx::approveWaiting($customer);
        Cx::add($variants[1]);
        Cx::place(Cx::start($address)->id);
    }

    solStaff();
    $section = app(OrdersCustomerSection::class)->sectionFor($customer);

    expect(count($section['orders'] ?? []))->toBe(10)
        ->and(array_column($section['orders'] ?? [], 'id'))->not->toContain($first)
        ->and(array_column($section['orders'] ?? [], 'id'))->not->toContain($someoneElses)
        // Each order as the panel's list draws it (§4.1 P7), its total written by the server.
        ->and(array_keys($section['orders'][0] ?? []))->toBe(['id', 'number', 'storeCode', 'placedAt', 'customer', 'status', 'shipment', 'payment', 'total', 'shortOfStock', 'shippingFeePending'])
        ->and([$section['customerId'] ?? null, $section['orders'][0]['storeCode'] ?? null, array_keys($section['orders'][0]['total'] ?? [])])->toBe([strtolower($customer), 'sa', ['text', 'minor']]);

    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['eg']));
    expect(app(OrdersCustomerSection::class)->sectionFor($customer))->toBe(['customerId' => strtolower($customer), 'orders' => []]);

    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE], ['sa']));
    expect(app(OrdersCustomerSection::class)->sectionFor($customer))->toBeNull();
});

it('names a wired order\'s sizes that may be ticked, and none of an order placed with no provider', function (bool $wired) {
    ['order' => $order, 'variants' => $variants] = solOrder($wired);
    // The second size's normal sales did not depend on its stock when placed: never tickable.
    DB::table('sales.order_lines')->where('order_id', $order)->where('variant_id', $variants[1])->where('is_gift', false)->update(['counts_on_stock' => false]);
    solStaff();

    $view = app(ViewOrderHandler::class)->handle(new ViewOrder($order));

    expect($view->wired)->toBe($wired)
        ->and($view->tickable)->toEqualCanonicalizing($wired ? array_values(array_map('strval', DB::table('sales.order_lines')->where('order_id', $order)->where('counts_on_stock', true)->pluck('variant_id')->unique()->all())) : [])
        ->and($view->tickable)->not->toContain($variants[1])
        ->and($wired ? $view->tickable : [$variants[0]])->toBe([$variants[0]]);
})->with(['wired' => [true], 'no provider' => [false]]);

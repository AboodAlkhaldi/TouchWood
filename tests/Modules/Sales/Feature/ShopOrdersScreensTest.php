<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturn;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturnHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Sales's account screens over HTTP (sales.md §4.1 S7–S10): My Orders beside the account's tabs, an order with
| its payment in words, Cancel and Buy Again, a return asked from a delivered order and withdrawn, and another
| customer's order found nowhere.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
});

/**
 * A customer's placed order in 'sa' - delivered and paid when asked - then the real ActorContext given back;
 * and a browser signed in as the customer.
 *
 * @return array{0: string, 1: string, 2: AdminBrowser, 3: array{customer: string, address: string, variants: list<string>, product: string}}
 */
function shopOrdersScreensOrder(bool $delivered = false): array
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();
        $placed = Cx::place(Cx::start($ready['address'])->id);

        if ($delivered) {
            Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
            app(ApproveOrderHandler::class)->handle(new ApproveOrder($placed->id));
            DB::table('sales.orders')->where('id', $placed->id)->update(['paid_minor' => DB::raw('due_minor')]);
            app(ShipOrderHandler::class)->handle(new ShipOrder($placed->id, 'SMSA', '123'));
            app(MarkDeliveredHandler::class)->handle(new MarkDelivered($placed->id));
        }
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));
    $email = (string) DB::table('access.customers')->where('id', $ready['customer'])->value('email');
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return [$placed->id, $placed->number, $browser, $ready];
}

/**
 * @return array<string, mixed>
 */
function shopOrdersScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

it('lists the customer\'s orders on My Orders, an account page, and shows one with its payment in words', function () {
    [, $number, $browser] = shopOrdersScreensOrder();

    $mine = shopOrdersScreensProps($browser, '/sa/en/account/orders', 'Sales/Storefront/MyOrders');
    $order = shopOrdersScreensProps($browser, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order');

    expect(array_column($mine['orders'], 'number'))->toBe([$number])
        ->and([$mine['orders'][0]['status'], $mine['orders'][0]['pieces']])->toBe(['NEW', 3])
        ->and(array_column($mine['accountMenu']['pages'], 'key'))->toContain('sales.orders')
        ->and([$order['status'], $order['payment'], $order['wayToPay'], $order['mayCancel'], $order['shipment']])->toBe(['NEW', 'UNPAID', 'STAFF_CONTACT', true, 'NOT_SHIPPED'])
        ->and(count($order['lines']))->toBe(2)
        ->and([$order['savings'], $order['coupon'], $order['points']])->toBe([null, null, null])
        // The number the courier calls, as the order was placed (access.md amendment 89).
        ->and($order['contactPhone'])->toBe(DB::table('sales.orders')->where('number', $number)->value('customer_phone'))
        ->and($order)->not->toHaveKey('addressPhone');
});

it('cancels the order with a reason, and buys it again into the cart', function () {
    [$id, $number, $browser] = shopOrdersScreensOrder();

    $refused = $browser->post("/sa/en/account/orders/{$number}/cancel", ['reason' => '']);
    $cancelled = $browser->post("/sa/en/account/orders/{$number}/cancel", ['reason' => 'FOUND_CHEAPER']);
    $again = $browser->post("/sa/en/account/orders/{$number}/buy-again");
    $cart = shopOrdersScreensProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');

    expect(AdminBrowser::formError($refused))->not->toBeNull()
        ->and(AdminBrowser::flashed($cancelled, 'status'))->toBe('Order cancelled.')
        ->and(DB::table('sales.orders')->where('id', $id)->value('status'))->toBe('CANCELLED')
        ->and($again->headers->get('Location'))->toEndWith('/sa/en/cart')
        ->and(AdminBrowser::flashed($again, 'status'))->toBe("Items from order {$number} added to your cart.")
        ->and([$cart['pieces'], $cart['skipped']])->toBe([3, []]);
});

it('asks a return of a delivered order\'s pieces, shows it, and withdraws it while it waits', function () {
    [$id, $number, $browser] = shopOrdersScreensOrder(delivered: true);

    $order = shopOrdersScreensProps($browser, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order');
    $ask = shopOrdersScreensProps($browser, "/sa/en/account/orders/{$number}/return", 'Sales/Storefront/AskReturn');
    $line = $ask['lines'][0];
    $asked = $browser->post("/sa/en/account/orders/{$number}/return", ['reason' => 'DAMAGED', 'lines' => [['orderLineId' => $line['id'], 'quantity' => 1]]]);
    $return = (string) DB::table('sales.returns')->where('order_id', $id)->value('id');
    $shown = shopOrdersScreensProps($browser, "/sa/en/account/returns/{$return}", 'Sales/Storefront/Return');
    $browser->post("/sa/en/account/returns/{$return}/withdraw");

    expect([$order['status'], $order['payment'], $order['mayReturn'], $order['carrier'], $order['mayCancel']])->toBe(['DELIVERED', 'PAID', true, 'SMSA', false])
        ->and($order['returnDeadline'])->not->toBeNull()
        ->and($line['returnable'])->toBeGreaterThan(0)
        ->and($asked->headers->get('Location'))->toEndWith("/sa/en/account/returns/{$return}")
        ->and([$shown['status'], $shown['reason'], $shown['orderNumber'], $shown['lines'][0]['asked']])->toBe(['REQUESTED', 'DAMAGED', $number, 1])
        ->and(DB::table('sales.returns')->where('id', $return)->value('status'))->toBe('WITHDRAWN');
});

it('shows the store\'s words on a refused return, on the customer\'s order and on the panel\'s (S8; B16)', function () {
    [$id, $number, $browser] = shopOrdersScreensOrder(delivered: true);
    $ask = shopOrdersScreensProps($browser, "/sa/en/account/orders/{$number}/return", 'Sales/Storefront/AskReturn');
    $browser->post("/sa/en/account/orders/{$number}/return", ['reason' => 'DAMAGED', 'lines' => [['orderLineId' => $ask['lines'][0]['id'], 'quantity' => 1]]]);
    $return = (string) DB::table('sales.returns')->where('order_id', $id)->value('id');
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $staff = Fx::staffWith([SalesPermissions::RETURN_MANAGE, SalesPermissions::ORDER_VIEW], ['sa']);
        Fx::actAsStaff($staff);
        app(RefuseReturnHandler::class)->handle(new RefuseReturn($return, 'Opened and used'));
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $order = shopOrdersScreensProps($browser, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order');
    $panel = new AdminBrowser('10.9.8.'.random_int(20, 250));
    $panel->post('/admin/sign-in', ['email' => (string) DB::table('access.staff_users')->where('id', $staff)->value('email'), 'password' => Fx::STAFF_PASSWORD])->assertRedirect('/admin/sign-in/code');
    $panel->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
    $shown = shopOrdersScreensProps($panel, "/admin/orders/{$id}", 'Sales/Admin/Orders/Show');

    expect(array_map(static fn (array $row): array => [$row['status'], $row['staffReason']], $order['returns']))->toBe([['REFUSED', 'Opened and used']])
        ->and(array_map(static fn (array $row): array => [$row['status'], $row['staffReason']], $shown['returns']))->toBe([['REFUSED', 'Opened and used']]);
});

it('asks a return with photos, each kept with it', function () {
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    [$id, $number, $browser] = shopOrdersScreensOrder(delivered: true);
    $line = shopOrdersScreensProps($browser, "/sa/en/account/orders/{$number}/return", 'Sales/Storefront/AskReturn')['lines'][0];

    $browser->post("/sa/en/account/orders/{$number}/return", [
        'reason' => 'DAMAGED',
        'lines' => [['orderLineId' => $line['id'], 'quantity' => 1]],
        'photos' => [UploadedFile::fake()->image('front.png', 40, 30), UploadedFile::fake()->image('back.png', 41, 30)],
    ])->assertRedirect();
    $return = (string) DB::table('sales.returns')->where('order_id', $id)->value('id');

    expect(DB::table('sales.return_photos')->where('return_id', $return)->count())->toBe(2)
        ->and(shopOrdersScreensProps($browser, "/sa/en/account/returns/{$return}", 'Sales/Storefront/Return')['photos'])->toBe(2);
});

it('finds another customer\'s order and return nowhere', function () {
    [, $number] = shopOrdersScreensOrder();
    [, , $other] = shopOrdersScreensOrder();

    $other->get("/sa/en/account/orders/{$number}")->assertNotFound();
    $other->get("/sa/en/account/orders/{$number}/return")->assertNotFound();
});

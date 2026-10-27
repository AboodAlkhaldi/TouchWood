<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\ActorContext;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Sales's order screens over HTTP (sales.md §4.1 P1–P3): the panel's list, an order's page with what the reader
| may do there, every change the page sends - approve, reject, cancel, ship, deliver, the money - and the packing
| slip, with the queries each page reads.
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

/** An order placed in 'sa' (two of a 100.00 size, one of a 50.00), waiting for approval, paid by staff contact. */
function orderScreensOrder(bool $wired = false): string
{
    // Placed as the customer, then the real ActorContext given back, so the requests after it read who is
    // acting from their own session (as LoyaltyScreensTest's aside).
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();

        // Wired to a provider before it is placed, its first size's sales made to hang on its stock (§1.5).
        if ($wired) {
            Ix::wired();
            app(InventoryApi::class)->makeStockDependent(StoreId::fromString(Fx::storeId('sa')), [$ready['variants'][0]]);
        }

        return Cx::place(Cx::start($ready['address'])->id)->id;
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/**
 * Staff with these jobs in these stores, signed in to the panel.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function orderScreensStaff(array $permissions, array $stores = ['sa']): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores);
    $browser = new AdminBrowser('10.9.4.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @return array<string, mixed>
 */
function orderScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

function orderScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Sales/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

/** @return array<string, mixed> */
function orderScreensRow(string $order): array
{
    return (array) DB::table('sales.orders')->where('id', $order)->first(['status', 'shipment_status', 'paid_minor', 'refunded_minor', 'due_minor']);
}

const ORDER_SCREENS_ALL = [
    SalesPermissions::ORDER_VIEW, SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_CANCEL,
    SalesPermissions::ORDER_FULFIL, SalesPermissions::PAYMENT_RECORD, SalesPermissions::ORDER_EDIT,
];

describe('P1 · Orders', function () {
    it('lists the reader\'s orders newest first, each with its states and total, and refuses a reader without the job', function () {
        $first = orderScreensOrder();
        $second = orderScreensOrder();
        $browser = orderScreensStaff([SalesPermissions::ORDER_VIEW]);

        $props = orderScreensProps($browser, '/admin/orders', 'Sales/Admin/Orders/Index');

        expect(array_column($props['orders'], 'id'))->toBe([$second, $first])
            ->and($props['orders'][0])->toMatchArray(['status' => 'PENDING_APPROVAL', 'payment' => 'UNPAID', 'shipment' => 'NOT_SHIPPED', 'storeCode' => 'sa'])
            ->and($props['orders'][0]['total']['minor'])->toBeGreaterThan(0)
            // Waiting for Approval opened first (R2-25).
            ->and($props['tab'])->toBe('WAITING_APPROVAL')
            ->and($props['tabs'])->toBe(['WAITING_APPROVAL', 'TO_SHIP', 'SHIPPED', 'UNPAID', 'SHORT', 'TRANSFERS_TO_CHECK', 'ALL']);
        orderScreensStaff([SalesPermissions::RETURN_MANAGE])->get('/admin/orders')->assertForbidden();
    });

    it('lists one store\'s unpaid orders when asked', function () {
        $order = orderScreensOrder();

        $props = orderScreensProps(orderScreensStaff([SalesPermissions::ORDER_VIEW]), '/admin/orders?store=sa&tab=UNPAID', 'Sales/Admin/Orders/Index');

        expect([array_column($props['orders'], 'id'), $props['storeCode'], $props['tab']])->toBe([[$order], 'sa', 'UNPAID']);
    });

    it('searches by the order number, and lists one customer\'s orders on every tab', function () {
        $found = orderScreensOrder();
        $other = orderScreensOrder();
        $number = (string) DB::table('sales.orders')->where('id', $found)->value('number');
        $customer = (string) DB::table('sales.orders')->where('id', $found)->value('customer_id');
        $browser = orderScreensStaff([SalesPermissions::ORDER_VIEW]);

        $searched = orderScreensProps($browser, '/admin/orders?tab=ALL&q='.urlencode($number), 'Sales/Admin/Orders/Index');
        $theirs = orderScreensProps($browser, "/admin/orders?tab=SHIPPED&customer={$customer}", 'Sales/Admin/Orders/Index');
        $tooShort = orderScreensProps($browser, '/admin/orders?tab=ALL&q=S', 'Sales/Admin/Orders/Index');

        expect([array_column($searched['orders'], 'id'), $searched['q']])->toBe([[$found], $number])
            ->and([array_column($theirs['orders'], 'id'), $theirs['tab'], $theirs['customerId']])->toBe([[$found], 'ALL', $customer])
            ->and($theirs['customerName'])->not->toBeNull()
            ->and(array_column($tooShort['orders'], 'id'))->toBe([$other, $found]);
    });
});

describe('P2 · An order', function () {
    it('shows the order with its lines, totals, what is owed, and the reader\'s jobs', function () {
        $order = orderScreensOrder();

        $viewer = orderScreensProps(orderScreensStaff([SalesPermissions::ORDER_VIEW]), "/admin/orders/{$order}", 'Sales/Admin/Orders/Show');
        $approver = orderScreensProps(orderScreensStaff([SalesPermissions::ORDER_VIEW, SalesPermissions::ORDER_APPROVE]), "/admin/orders/{$order}", 'Sales/Admin/Orders/Show');

        expect(count($viewer['lines']))->toBe(2)
            ->and($viewer['owed']['minor'])->toBe($viewer['total']['minor'])
            ->and($viewer['refundDue']['minor'])->toBe(0)
            ->and($viewer['actions']['mayApprove'])->toBeFalse()
            ->and($approver['actions']['mayApprove'])->toBeTrue()
            ->and($viewer['number'])->toStartWith('SA-');
    });

    it('approves, takes the whole payment, ships, and marks it delivered, saying each', function () {
        $order = orderScreensOrder();
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);
        $base = "/admin/orders/{$order}";

        $approved = $browser->post("{$base}/approve");
        $refusedShip = $browser->post("{$base}/ship", ['carrier' => 'SMSA']);
        $shown = orderScreensProps($browser, $base, 'Sales/Admin/Orders/Show')['owedDecimal'];
        // An amount sent is never read: the whole balance is recorded (§4.2 B1), the one the page showed.
        $paid = $browser->post("{$base}/payment", ['amount' => '1.00', 'shown' => $shown, 'way' => 'BANK_TRANSFER', 'reference' => 'TR-1', 'happened_on' => now('Asia/Riyadh')->toDateString(), 'request_id' => (string) Str::uuid()]);
        $shipped = $browser->post("{$base}/ship", ['carrier' => 'SMSA', 'tracking_number' => '123']);
        $delivered = $browser->post("{$base}/deliver");
        $row = orderScreensRow($order);

        expect([AdminBrowser::flashed($approved, 'status'), AdminBrowser::flashed($paid, 'status'), AdminBrowser::flashed($shipped, 'status'), AdminBrowser::flashed($delivered, 'status')])
            ->toBe(['Order approved.', 'Payment recorded.', 'Order shipped.', 'Order marked delivered.'])
            ->and(AdminBrowser::formError($refusedShip))->not->toBeNull()
            ->and($row)->toMatchArray(['status' => 'COMPLETED', 'shipment_status' => 'DELIVERED'])
            ->and($row['paid_minor'])->toBe($row['due_minor']);
    });

    it('refuses a payment once what is owed is no longer what the page showed', function () {
        $order = orderScreensOrder();
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);
        $shown = orderScreensProps($browser, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show')['owedDecimal'];
        // An edit raised the total meanwhile.
        DB::table('sales.orders')->where('id', $order)->update(['due_minor' => DB::raw('due_minor + 5000'), 'order_total_minor' => DB::raw('order_total_minor + 5000')]);

        $stale = $browser->post("/admin/orders/{$order}/payment", ['shown' => $shown, 'way' => 'BANK_TRANSFER', 'happened_on' => now('Asia/Riyadh')->toDateString(), 'request_id' => (string) Str::uuid()]);

        expect(AdminBrowser::formError($stale))->not->toBeNull()
            ->and(orderScreensRow($order)['paid_minor'])->toBe(0);
    });

    it('offers the carriers the store shipped with before, and ticks a wired order\'s sizes reduced in the provider', function () {
        $shipped = orderScreensOrder();
        $wired = orderScreensOrder(wired: true);
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);
        $browser->post("/admin/orders/{$shipped}/approve");
        $browser->post("/admin/orders/{$shipped}/ship-unpaid", ['allow' => true]);
        $browser->post("/admin/orders/{$shipped}/ship", ['carrier' => 'Aramex']);
        $browser->post("/admin/orders/{$wired}/approve");

        $page = orderScreensProps($browser, "/admin/orders/{$wired}", 'Sales/Admin/Orders/Show');
        $ticked = $browser->post("/admin/orders/{$wired}/tick", ['variants' => [$page['tickable'][0]]]);
        $after = orderScreensProps($browser, "/admin/orders/{$wired}", 'Sales/Admin/Orders/Show');
        $viewer = orderScreensProps(orderScreensStaff([SalesPermissions::ORDER_VIEW]), "/admin/orders/{$wired}", 'Sales/Admin/Orders/Show');

        expect([$page['carriers'], $page['wired'], $viewer['carriers']])->toBe([['Aramex'], true, []])
            ->and($page['tickable'])->not->toBe([])
            ->and(AdminBrowser::flashed($ticked, 'status'))->toBe('Ticks saved.')
            ->and(array_column($after['ticks'], 'variantId'))->toBe([$page['tickable'][0]])
            ->and(orderScreensProps($browser, "/admin/orders/{$shipped}", 'Sales/Admin/Orders/Show')['wired'])->toBeFalse();
    });

    it('rejects a waiting order with a reason, and cancels another', function () {
        $rejected = orderScreensOrder();
        $cancelled = orderScreensOrder();
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);

        $noReason = $browser->post("/admin/orders/{$rejected}/reject", ['reason' => '']);
        $browser->post("/admin/orders/{$rejected}/reject", ['reason' => 'Duplicate order']);
        $done = $browser->post("/admin/orders/{$cancelled}/cancel", ['reason' => 'The customer asked']);

        expect(AdminBrowser::formError($noReason))->not->toBeNull()
            ->and(orderScreensRow($rejected)['status'])->toBe('REJECTED')
            ->and(AdminBrowser::flashed($done, 'status'))->toBe('Order cancelled.')
            ->and(orderScreensRow($cancelled)['status'])->toBe('CANCELLED');
    });

    it('refunds part of what a cancelled paid order holds, and voids a record', function () {
        $order = orderScreensOrder();
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);
        $day = now('Asia/Riyadh')->toDateString();
        $browser->post("/admin/orders/{$order}/payment", ['way' => 'OTHER', 'note' => 'Cash at the branch', 'happened_on' => $day, 'request_id' => (string) Str::uuid()]);
        $browser->post("/admin/orders/{$order}/cancel", ['reason' => 'Out of stock']);

        $refunded = $browser->post("/admin/orders/{$order}/refund", ['amount' => '10.00', 'way' => 'BANK_TRANSFER', 'happened_on' => $day, 'request_id' => (string) Str::uuid()]);
        $refund = (string) DB::table('sales.refunds')->where('order_id', $order)->value('id');
        $recorded = (int) DB::table('sales.refunds')->where('id', $refund)->value('amount_minor');
        $voided = $browser->post("/admin/orders/{$order}/void", ['kind' => 'REFUND', 'record' => $refund, 'reason' => 'Typed twice']);
        $props = orderScreensProps($browser, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show');

        expect([AdminBrowser::flashed($refunded, 'status'), AdminBrowser::flashed($voided, 'status')])->toBe(['Refund recorded.', 'Record voided.'])
            ->and($recorded)->toBe(10_00)
            ->and($props['refundDue']['minor'])->toBe($props['paid']['minor'])
            ->and(array_column((array) $props['money'], 'voidReason', 'id')[$refund] ?? null)->toBe('Typed twice');
    });

    it('reads an order\'s page, and the list, in a fixed few queries of their own, the list the same for one order as for six', function () {
        $order = orderScreensOrder();
        $one = orderScreensOwnQueries(orderScreensStaff([SalesPermissions::ORDER_VIEW]), '/admin/orders');

        foreach (range(1, 5) as $n) {
            orderScreensOrder();
        }

        // A fresh browser: a session signed in before the fixtures above is not the one after them.
        $browser = orderScreensStaff([SalesPermissions::ORDER_VIEW]);

        expect(orderScreensOwnQueries($browser, "/admin/orders/{$order}"))->toBe(12)
            ->and(orderScreensOwnQueries($browser, '/admin/orders'))->toBe($one)
            ->and($one)->toBe(4);
    });
});

describe('P2 · The rest of an order\'s changes, and another store\'s orders', function () {
    it('allows shipping unpaid, ships, and marks the parcel came back with its pieces back in stock', function () {
        $order = orderScreensOrder();
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);
        $browser->post("/admin/orders/{$order}/approve");

        $allowed = $browser->post("/admin/orders/{$order}/ship-unpaid", ['allow' => true]);
        $browser->post("/admin/orders/{$order}/ship", ['carrier' => 'Aramex']);
        $lines = DB::table('sales.order_lines')->where('order_id', $order)->get(['variant_id', 'quantity', 'is_gift'])
            ->map(fn ($line): array => ['variantId' => (string) $line->variant_id, 'quantity' => (int) $line->quantity, 'gift' => (bool) $line->is_gift])->all();
        $back = $browser->post("/admin/orders/{$order}/came-back", ['put_back' => array_values($lines)]);

        expect([AdminBrowser::flashed($allowed, 'status'), AdminBrowser::flashed($back, 'status')])->toBe(['This order may now ship before it is paid.', 'Parcel marked came back.'])
            ->and(orderScreensRow($order))->toMatchArray(['status' => 'UNDELIVERED', 'shipment_status' => 'CAME_BACK']);
    });

    it('refuses voiding a payment once what stays paid would not cover the refunds, and a record of another order', function () {
        $order = orderScreensOrder();
        $other = orderScreensOrder();
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);
        $day = now('Asia/Riyadh')->toDateString();
        $browser->post("/admin/orders/{$order}/payment", ['way' => 'BANK_TRANSFER', 'happened_on' => $day, 'request_id' => (string) Str::uuid()]);
        $browser->post("/admin/orders/{$order}/cancel", ['reason' => 'Out of stock']);
        $props = orderScreensProps($browser, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show');
        $browser->post("/admin/orders/{$order}/refund", ['amount' => (string) ($props['paid']['minor'] / 100), 'way' => 'BANK_TRANSFER', 'happened_on' => $day, 'request_id' => (string) Str::uuid()]);
        $payment = (string) DB::table('sales.payments')->where('order_id', $order)->value('id');

        $refused = $browser->post("/admin/orders/{$order}/void", ['kind' => 'PAYMENT', 'record' => $payment, 'reason' => 'Typed twice']);
        $elsewhere = $browser->post("/admin/orders/{$other}/void", ['kind' => 'PAYMENT', 'record' => $payment, 'reason' => 'Typed twice']);

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and($elsewhere->getStatusCode())->toBe(404)
            ->and(DB::table('sales.payments')->where('id', $payment)->value('voided_at'))->toBeNull();
    });

    it('shows another store\'s order to nobody who does not cover it, and its jobs as not held', function () {
        $order = orderScreensOrder();
        $elsewhere = orderScreensStaff([SalesPermissions::ORDER_VIEW, SalesPermissions::ORDER_APPROVE], ['ae']);

        $elsewhere->get("/admin/orders/{$order}")->assertNotFound();
        $elsewhere->get("/admin/orders/{$order}/print")->assertNotFound();
        $refused = $elsewhere->post("/admin/orders/{$order}/approve");
        $list = orderScreensProps($elsewhere, '/admin/orders', 'Sales/Admin/Orders/Index');
        $viewHere = orderScreensProps(orderScreensStaff([SalesPermissions::ORDER_VIEW], ['sa', 'ae']), "/admin/orders/{$order}", 'Sales/Admin/Orders/Show');

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(orderScreensRow($order)['status'])->toBe('PENDING_APPROVAL')
            ->and($list['orders'])->toBe([])
            ->and($viewHere['actions']['mayApprove'])->toBeFalse();
        $elsewhere->get('/admin/orders?store=sa')->assertNotFound();
    });
});

describe('P7 · A customer\'s orders on the staff customer page', function () {
    it('lists the customer\'s latest orders under the other sections, for a reader who views orders', function () {
        $order = orderScreensOrder();
        $customer = (string) DB::table('sales.orders')->where('id', $order)->value('customer_id');

        $props = orderScreensProps(orderScreensStaff([AccessPermissions::CUSTOMER_VIEW, SalesPermissions::ORDER_VIEW]), "/admin/customers/{$customer}", 'Access/Admin/Customers/Show');
        $sales = array_values(array_filter($props['sections'], static fn (array $section): bool => $section['module'] === 'sales'));
        $without = orderScreensProps(orderScreensStaff([AccessPermissions::CUSTOMER_VIEW]), "/admin/customers/{$customer}", 'Access/Admin/Customers/Show');

        expect(array_column($sales[0]['data']['orders'] ?? [], 'id'))->toBe([$order])
            ->and([$sales[0]['key'], $sales[0]['data']['customerId']])->toBe(['orders', $customer])
            ->and(array_column($without['sections'], 'module'))->not->toContain('sales');
    });
});

describe('P4 · Editing an order', function () {
    it('shows the lines to edit, previews the edit with the money in words, and confirms it with a reason', function () {
        $order = orderScreensOrder();
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);
        $page = orderScreensProps($browser, "/admin/orders/{$order}/edit", 'Sales/Admin/Orders/Edit');
        $lines = array_map(static fn (array $line): array => ['variantId' => $line['variantId'], 'mode' => $line['mode'], 'quantity' => $line['quantity'] + 1], $page['lines']);
        $query = http_build_query(['lines' => $lines, 'gift' => 'KEEP']);

        $sizes = $browser->get("/admin/orders/{$order}/edit/sizes?q=")->assertOk()->json();
        $preview = $browser->get("/admin/orders/{$order}/edit/preview?{$query}")->assertOk()->json();
        $noReason = $browser->post("/admin/orders/{$order}/edit", ['lines' => $lines, 'gift' => 'KEEP', 'reason' => '']);
        $saved = $browser->post("/admin/orders/{$order}/edit", ['lines' => $lines, 'gift' => 'KEEP', 'reason' => 'The customer asked for one more of each']);

        expect(count($page['lines']))->toBe(2)
            ->and(is_array($sizes))->toBeTrue()
            ->and($preview['money'])->toBe('MORE')
            ->and($preview['total']['minor'])->toBeGreaterThan($preview['before']['minor'])
            ->and(AdminBrowser::formError($noReason))->not->toBeNull()
            ->and($saved->headers->get('Location'))->toEndWith("/admin/orders/{$order}")
            ->and(AdminBrowser::flashed($saved, 'status'))->toBe('Order edited.')
            ->and((int) DB::table('sales.orders')->where('id', $order)->value('order_total_minor'))->toBe($preview['total']['minor']);

        // Edited again: a raised line's parts are one line, and the same lines again change no money.
        $again = orderScreensProps($browser, "/admin/orders/{$order}/edit", 'Sales/Admin/Orders/Edit');
        $same = $browser->get("/admin/orders/{$order}/edit/preview?{$query}")->assertOk()->json();
        expect(array_map(static fn (array $line): int => $line['quantity'], $again['lines']))->toBe(array_column($lines, 'quantity'))
            ->and([$same['money'], $same['difference']['minor']])->toBe(['NONE', 0]);
    });

    it('refuses the edit page to a reader without the job, and answers a refused preview in its own words', function () {
        $order = orderScreensOrder();
        $browser = orderScreensStaff(ORDER_SCREENS_ALL);

        orderScreensStaff([SalesPermissions::ORDER_VIEW])->get("/admin/orders/{$order}/edit")->assertForbidden();
        $refused = $browser->get("/admin/orders/{$order}/edit/preview?gift=KEEP");

        expect($refused->getStatusCode())->toBe(422)
            ->and($refused->json('refused'))->toBeString();
    });
});

describe('P3 · The packing slip', function () {
    it('prints the lines with their codes', function () {
        $order = orderScreensOrder();

        $props = orderScreensProps(orderScreensStaff([SalesPermissions::ORDER_VIEW]), "/admin/orders/{$order}/print", 'Sales/Admin/Orders/Print');

        expect(count($props['lines']))->toBe(2)
            ->and($props['lines'][0]['code'])->not->toBe('');
    });
});

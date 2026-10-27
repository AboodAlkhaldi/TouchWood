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
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
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
function orderScreensOrder(): string
{
    // Placed as the customer, then the real ActorContext given back, so the requests after it read who is
    // acting from their own session (as LoyaltyScreensTest's aside).
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();

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
            ->and($props['tab'])->toBe('ALL')
            ->and($props['tabs'])->toContain('ALL', 'UNPAID', 'SHORT');
        orderScreensStaff([SalesPermissions::RETURN_MANAGE])->get('/admin/orders')->assertForbidden();
    });

    it('lists one store\'s unpaid orders when asked', function () {
        $order = orderScreensOrder();

        $props = orderScreensProps(orderScreensStaff([SalesPermissions::ORDER_VIEW]), '/admin/orders?store=sa&tab=UNPAID', 'Sales/Admin/Orders/Index');

        expect([array_column($props['orders'], 'id'), $props['storeCode'], $props['tab']])->toBe([[$order], 'sa', 'UNPAID']);
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
        // An amount sent is never read: the whole balance is recorded (§4.2 B1).
        $paid = $browser->post("{$base}/payment", ['amount' => '1.00', 'way' => 'BANK_TRANSFER', 'reference' => 'TR-1', 'happened_on' => now('Asia/Riyadh')->toDateString(), 'request_id' => (string) Str::uuid()]);
        $shipped = $browser->post("{$base}/ship", ['carrier' => 'SMSA', 'tracking_number' => '123']);
        $delivered = $browser->post("{$base}/deliver");
        $row = orderScreensRow($order);

        expect([AdminBrowser::flashed($approved, 'status'), AdminBrowser::flashed($paid, 'status'), AdminBrowser::flashed($shipped, 'status'), AdminBrowser::flashed($delivered, 'status')])
            ->toBe(['Order approved.', 'Payment recorded.', 'Order shipped.', 'Order marked delivered.'])
            ->and(AdminBrowser::formError($refusedShip))->not->toBeNull()
            ->and($row)->toMatchArray(['status' => 'COMPLETED', 'shipment_status' => 'DELIVERED'])
            ->and($row['paid_minor'])->toBe($row['due_minor']);
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

describe('P3 · The packing slip', function () {
    it('prints the lines with their codes', function () {
        $order = orderScreensOrder();

        $props = orderScreensProps(orderScreensStaff([SalesPermissions::ORDER_VIEW]), "/admin/orders/{$order}/print", 'Sales/Admin/Orders/Print');

        expect(count($props['lines']))->toBe(2)
            ->and($props['lines'][0]['code'])->not->toBe('');
    });
});

<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
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
| Sales's return screens over HTTP (sales.md §4.1 P5, P6): the panel's list by state, a return's page, and the
| changes it sends - accept, refuse with a reason, receive, close, complete, then the refund recorded on the order.
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
 * A delivered, paid order and a return asked of all its pieces; then the real ActorContext given back.
 *
 * @return array{order: string, return: string}
 */
function returnScreensReturn(): array
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();
        $order = Cx::place(Cx::start($ready['address'])->id)->id;
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, 'SMSA', null));
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
        Fx::actAsCustomer($ready['customer']);
        $lines = DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->get(['id', 'quantity'])
            ->map(fn ($line): array => ['orderLineId' => (string) $line->id, 'quantity' => (int) $line->quantity])->all();
        $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, array_values($lines)));

        return ['order' => $order, 'return' => $return];
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/**
 * @param  list<string>  $permissions
 */
function returnScreensStaff(array $permissions): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, ['sa']);
    $browser = new AdminBrowser('10.9.3.'.random_int(20, 250));

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
function returnScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

const RETURN_SCREENS_ALL = [SalesPermissions::ORDER_VIEW, SalesPermissions::RETURN_MANAGE, SalesPermissions::PAYMENT_RECORD];

it('lists the requested returns, and shows one with its lines and the reader\'s jobs', function () {
    ['return' => $return] = returnScreensReturn();
    $browser = returnScreensStaff(RETURN_SCREENS_ALL);

    $list = returnScreensProps($browser, '/admin/returns', 'Sales/Admin/Returns/Index');
    $page = returnScreensProps($browser, "/admin/returns/{$return}", 'Sales/Admin/Returns/Show');
    $viewer = returnScreensProps(returnScreensStaff([SalesPermissions::ORDER_VIEW]), "/admin/returns/{$return}", 'Sales/Admin/Returns/Show');

    expect([array_column($list['returns'], 'id'), $list['tab']])->toBe([[$return], 'REQUESTED'])
        ->and([$page['status'], $page['reason'], count($page['lines']), $page['mayManage']])->toBe(['REQUESTED', 'DAMAGED', 2, true])
        ->and($viewer['mayManage'])->toBeFalse();
});

it('accepts, receives, completes and records the refund on the order, saying each', function () {
    ['order' => $order, 'return' => $return] = returnScreensReturn();
    $browser = returnScreensStaff(RETURN_SCREENS_ALL);
    $lines = DB::table('sales.return_lines')->where('return_id', $return)->get(['order_line_id', 'quantity'])
        ->map(fn ($line): array => ['orderLineId' => (string) $line->order_line_id, 'received' => (int) $line->quantity, 'restocked' => (int) $line->quantity])->all();

    $accepted = $browser->post("/admin/returns/{$return}/accept");
    $received = $browser->post("/admin/returns/{$return}/receive", ['lines' => array_values($lines)]);
    $preview = returnScreensProps($browser, "/admin/returns/{$return}", 'Sales/Admin/Returns/Show')['preview'];
    $completed = $browser->post("/admin/returns/{$return}/complete");
    $page = returnScreensProps($browser, "/admin/returns/{$return}", 'Sales/Admin/Returns/Show');
    $refunded = $browser->post("/admin/orders/{$order}/refund", ['amount' => $page['refundDueDecimal'], 'way' => 'BANK_TRANSFER', 'happened_on' => now('Asia/Riyadh')->toDateString(), 'request_id' => (string) Str::uuid()]);

    expect([AdminBrowser::flashed($accepted, 'status'), AdminBrowser::flashed($received, 'status'), AdminBrowser::flashed($completed, 'status'), AdminBrowser::flashed($refunded, 'status')])
        ->toBe(['Return accepted.', 'Return marked received.', 'Return completed. Record the refund.', 'Refund recorded.'])
        ->and($page['status'])->toBe('COMPLETED')
        ->and($page['refundDue']['minor'])->toBeGreaterThan(0)
        ->and((int) DB::table('sales.orders')->where('id', $order)->value('refunded_minor'))->toBe($page['refundDue']['minor'])
        // The refund Complete showed is the refund it made (R2-40); none shown once completed.
        ->and($preview['total']['minor'] ?? null)->toBe($page['refundDue']['minor'])
        ->and($page['preview'])->toBeNull();
});

it('refuses a return with a reason, and refuses a reader without the job', function () {
    ['return' => $return] = returnScreensReturn();

    $denied = returnScreensStaff([SalesPermissions::ORDER_VIEW])->post("/admin/returns/{$return}/refuse", ['reason' => 'No']);
    $refused = returnScreensStaff(RETURN_SCREENS_ALL)->post("/admin/returns/{$return}/refuse", ['reason' => 'Outside the window']);

    expect(AdminBrowser::formError($denied))->not->toBeNull()
        ->and(AdminBrowser::flashed($refused, 'status'))->toBe('Return refused.')
        ->and(DB::table('sales.returns')->where('id', $return)->value('staff_reason'))->toBe('Outside the window')
        ->and(array_column(returnScreensProps(returnScreensStaff(RETURN_SCREENS_ALL), '/admin/returns?tab=CLOSED_ALL', 'Sales/Admin/Returns/Index')['returns'], 'id'))->toBe([$return]);
});

<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;
use Modules\Sales\Application\Command\AllowShipUnpaid\AllowShipUnpaid;
use Modules\Sales\Application\Command\AllowShipUnpaid\AllowShipUnpaidHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\Command\ClearShortFlag\ClearShortFlag;
use Modules\Sales\Application\Command\ClearShortFlag\ClearShortFlagHandler;
use Modules\Sales\Application\Command\EditOrder\EditOrder;
use Modules\Sales\Application\Command\EditOrder\EditOrderHandler;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBack;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBackHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RecordRefund\RecordRefund;
use Modules\Sales\Application\Command\RecordRefund\RecordRefundHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\TickReducedInProvider\TickReducedInProvider;
use Modules\Sales\Application\Command\TickReducedInProvider\TickReducedInProviderHandler;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecord;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecordHandler;
use Modules\Sales\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Sales\Application\Query\ListOrders\ListOrdersHandler;
use Modules\Sales\Application\Query\OpenOrderFile\OpenOrderFile;
use Modules\Sales\Application\Query\OpenOrderFile\OpenOrderFileHandler;
use Modules\Sales\Application\Query\PreviewOrderEdit\PreviewOrderEditHandler;
use Modules\Sales\Application\Query\PrintOrder\PrintOrderHandler;
use Modules\Sales\Application\Query\SearchEditSizes\SearchEditSizesHandler;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Presentation\Http\Resource\EditPages;
use Modules\Sales\Presentation\Http\Resource\EditSizeData;
use Modules\Sales\Presentation\Http\Resource\OrderPages;
use Shared\Domain\Error\DomainError;

/*
| P1–P3 - the panel's orders, an order and its packing slip (sales.md §4.1), and every change an order's page
| makes: approve, reject, cancel, ship, deliver, came back, the provider ticks, shipping unpaid, the money
| recorded, a void, the short-of-stock flag. Each sends one of §3's commands, which asks again; a refusal comes
| back to the page in its own words (FormErrors), a done change says so.
*/
final readonly class OrdersController
{
    /** @var list<string> */
    private const array WORDS = ['sales::admin_orders', 'sales::errors', 'admin'];

    public function __construct(
        private Page $page,
        private OrderPages $pages,
    ) {}

    public function index(Request $request, ListOrdersHandler $list): Response
    {
        return $this->page->render('Sales/Admin/Orders/Index', $this->pages->list(self::query($request, 'store'), self::query($request, 'tab') ?? OrderPages::TABS[0], self::query($request, 'after'), self::query($request, 'q'), self::query($request, 'customer'), $list)->toArray(), self::WORDS);
    }

    public function show(string $order, ViewOrderHandler $view, ListCarriersHandler $carriers): Response
    {
        return $this->page->render('Sales/Admin/Orders/Show', $this->pages->order($order, $view, $carriers)->toArray(), self::WORDS);
    }

    public function print(string $order, PrintOrderHandler $print): Response
    {
        return $this->page->render('Sales/Admin/Orders/Print', $this->pages->print($order, $print)->toArray(), self::WORDS);
    }

    /** A transfer receipt, through Platform's expiring link (§3.2 `OpenOrderFile`). */
    public function file(string $media, OpenOrderFileHandler $open): RedirectResponse
    {
        return redirect()->away($open->handle(new OpenOrderFile($media))->url);
    }

    public function approve(Request $request, string $order, ApproveOrderHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new ApproveOrder($order)), 'approved');
    }

    public function reject(Request $request, string $order, RejectOrderHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RejectOrder($order, self::text($request, 'reason'))), 'rejected');
    }

    public function cancel(Request $request, string $order, CancelOrderHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new CancelOrder($order, self::text($request, 'reason'))), 'cancelled');
    }

    public function ship(Request $request, string $order, ShipOrderHandler $handler): RedirectResponse
    {
        $tracking = trim(self::text($request, 'tracking_number'));

        return self::act($request, fn () => $handler->handle(new ShipOrder($order, trim(self::text($request, 'carrier')), $tracking === '' ? null : $tracking)), 'shipped');
    }

    public function deliver(Request $request, string $order, MarkDeliveredHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new MarkDelivered($order)), 'delivered');
    }

    public function cameBack(Request $request, string $order, MarkCameBackHandler $handler): RedirectResponse
    {
        $sent = $request->input('put_back');
        $putBack = [];

        foreach (is_array($sent) ? $sent : [] as $line) {
            if (is_array($line) && is_string($line['variantId'] ?? null) && is_numeric($line['quantity'] ?? null) && (int) $line['quantity'] > 0) {
                $putBack[] = ['variantId' => $line['variantId'], 'quantity' => (int) $line['quantity'], 'gift' => (bool) ($line['gift'] ?? false)];
            }
        }

        return self::act($request, fn () => $handler->handle(new MarkCameBack($order, $putBack)), 'came_back');
    }

    public function tick(Request $request, string $order, TickReducedInProviderHandler $handler): RedirectResponse
    {
        $sent = $request->input('variants');
        $variants = array_values(array_filter(is_array($sent) ? $sent : [], 'is_string'));

        return self::act($request, fn () => $handler->handle(new TickReducedInProvider($order, $variants)), 'ticked');
    }

    public function shipUnpaid(Request $request, string $order, AllowShipUnpaidHandler $handler): RedirectResponse
    {
        $allow = $request->boolean('allow');

        return self::act($request, fn () => $handler->handle(new AllowShipUnpaid($order, $allow)), $allow ? 'ship_unpaid_on' : 'ship_unpaid_off');
    }

    public function payment(Request $request, string $order, RecordPaymentHandler $handler): RedirectResponse
    {
        // The whole amount still owed, worked out by Record Payment (§4.2 B1, the owner's R3-1); the amount the
        // page showed goes with it, refused once an edit or a refund changed it meanwhile.
        return self::act($request, fn () => $handler->handle(new RecordPayment(
            $order, self::text($request, 'way'), self::optional($request, 'reference'), self::optional($request, 'note'),
            self::text($request, 'happened_on'), self::requestId($request), self::optional($request, 'shown'),
        )), 'payment');
    }

    public function refund(Request $request, string $order, RecordRefundHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RecordRefund(
            $order, self::text($request, 'amount'), self::text($request, 'way'), self::optional($request, 'reference'), self::optional($request, 'note'),
            self::text($request, 'happened_on'), self::requestId($request),
        )), 'refund');
    }

    public function void(Request $request, string $order, VoidMoneyRecordHandler $handler, ViewOrderHandler $view, ListCarriersHandler $carriers): RedirectResponse
    {
        // A record of this order only: the address names the order the toast is said on.
        $record = self::text($request, 'record');

        if (! in_array($record, array_map(static fn ($row): string => $row->id, $this->pages->order($order, $view, $carriers)->money), true)) {
            abort(404);
        }

        return self::act($request, fn () => $handler->handle(new VoidMoneyRecord(self::text($request, 'kind'), self::text($request, 'record'), self::text($request, 'reason'))), 'voided');
    }

    /** P4 - editing the order, on its own page (R1-49). */
    public function edit(string $order, ViewOrderHandler $view, EditPages $pages): Response
    {
        return $this->page->render('Sales/Admin/Orders/Edit', $pages->page($order, $view)->toArray(), self::WORDS);
    }

    /** Add Product's search (§4.2 B7): JSON, as it is typed. */
    public function editSizes(Request $request, string $order, EditPages $pages, SearchEditSizesHandler $search, ViewOrderHandler $view): JsonResponse
    {
        try {
            $rows = $pages->sizes($order, mb_substr(trim(self::text($request, 'q')), 0, 100), $search, $view);
        } catch (DomainError) {
            $rows = [];
        }

        return response()->json(array_map(static fn (EditSizeData $row): array => $row->toArray(), $rows));
    }

    /** Preview: the edit worked out, nothing written (§1.7); its refusal in its own words. */
    public function editPreview(Request $request, string $order, EditPages $pages, PreviewOrderEditHandler $previews, ViewOrderHandler $view): JsonResponse
    {
        try {
            return response()->json($pages->preview($order, self::lines($request), self::optional($request, 'address'), self::gift($request), $previews, $view)->toArray());
        } catch (DomainError $error) {
            return response()->json(['refused' => FormErrors::message($error)], 422);
        }
    }

    /** Confirm: the edit as previewed, with its reason, then back to the order. */
    public function saveEdit(Request $request, string $order, EditOrderHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new EditOrder($order, self::text($request, 'reason'), self::lines($request), self::optional($request, 'address'), self::gift($request)));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->route('sales.admin.orders.show', ['order' => $order])->with('status', __('sales::admin_orders.toast.edited'));
    }

    /**
     * The order's lines as sent, gift aside: `[{variantId, mode, quantity}]`.
     *
     * @return list<array{variantId: string, mode: string, quantity: int}>
     */
    private static function lines(Request $request): array
    {
        $sent = $request->input('lines');
        $lines = [];

        foreach (is_array($sent) ? $sent : [] as $line) {
            if (is_array($line) && is_string($line['variantId'] ?? null) && is_string($line['mode'] ?? null) && is_numeric($line['quantity'] ?? null)) {
                $lines[] = ['variantId' => $line['variantId'], 'mode' => $line['mode'], 'quantity' => (int) $line['quantity']];
            }
        }

        return $lines;
    }

    private static function gift(Request $request): string
    {
        return self::optional($request, 'gift') ?? 'KEEP';
    }

    public function clearShort(Request $request, string $order, ClearShortFlagHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new ClearShortFlag($order)), 'short_cleared');
    }

    private static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __("sales::admin_orders.toast.{$done}"));
    }

    /** The form's own id, so pressing twice records once (§1.9); a fresh one when the page sent none. */
    private static function requestId(Request $request): string
    {
        $id = $request->input('request_id');

        return is_string($id) && Str::isUuid($id) ? $id : (string) Str::uuid();
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    private static function optional(Request $request, string $key): ?string
    {
        $value = trim(self::text($request, $key));

        return $value === '' ? null : $value;
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

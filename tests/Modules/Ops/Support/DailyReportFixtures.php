<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ops\Application\DailyReport\DailyReport;
use Modules\Ops\Application\DailyReport\DailyReportRenderer;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturn;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturnHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturn;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturnHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

/**
 * A day of a store's sales for the daily report's tests (ops.md §8), made through Sales' own use cases:
 * an order delivered (two of the 100.00 size, one of the 50.00), paid by transfer today, with one piece
 * returned and refunded and one return still waiting; an order cancelled; an order rejected.
 */
final class DailyReportFixtures
{
    /**
     * @return array{delivered: string, cancelled: string, rejected: string, number: string, variants: list<string>, done: string, open: string}
     */
    public static function aDay(): array
    {
        $delivered = self::order();
        $staff = self::staff();
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($delivered['order']));
        app(RecordPaymentHandler::class)->handle(new RecordPayment($delivered['order'], 'BANK_TRANSFER', 'T-1', null, CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d'), (string) Str::uuid()));
        app(ShipOrderHandler::class)->handle(new ShipOrder($delivered['order'], 'SMSA', 'TRK-1'));
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($delivered['order']));
        $line = static fn (int $quantity): string => (string) DB::table('sales.order_lines')->where('order_id', $delivered['order'])->where('is_gift', false)->where('quantity', $quantity)->value('id');

        $ask = static function (string $line) use ($delivered, $staff): string {
            Fx::actAsCustomer($delivered['customer']);
            $return = app(RequestReturnHandler::class)->handle(new RequestReturn($delivered['order'], 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));
            Fx::actAsStaff($staff);

            return $return;
        };
        $done = $ask($line(2));
        app(AcceptReturnHandler::class)->handle(new AcceptReturn($done));
        app(ReceiveReturnHandler::class)->handle(new ReceiveReturn($done, [['orderLineId' => $line(2), 'received' => 1, 'restocked' => 1]]));
        app(CompleteReturnHandler::class)->handle(new CompleteReturn($done));
        $open = $ask($line(1));

        $cancelled = self::order();
        self::staff();
        app(CancelOrderHandler::class)->handle(new CancelOrder($cancelled['order'], 'The customer called'));
        $rejected = self::order();
        self::staff();
        app(RejectOrderHandler::class)->handle(new RejectOrder($rejected['order'], 'Out of the delivery area'));

        return [
            'delivered' => $delivered['order'],
            'cancelled' => $cancelled['order'],
            'rejected' => $rejected['order'],
            'number' => $delivered['number'],
            'variants' => $delivered['variants'],
            'done' => $done,
            'open' => $open,
        ];
    }

    /** A renderer that writes no PDF, for the tests about who may do what: mPDF takes a second a report. */
    public static function fakeRenderer(): void
    {
        app()->bind(DailyReportRenderer::class, fn () => new class implements DailyReportRenderer
        {
            public function render(DailyReport $report, string $locale): string
            {
                return '%PDF-1.4 '.$locale.' '.count($report->stores).' '.$report->madeAt->format('U.u');
            }
        });
    }

    /** @return array{order: string, number: string, customer: string, variants: list<string>} */
    private static function order(): array
    {
        $ready = Cx::ready();
        $placed = Cx::place(Cx::start($ready['address'])->id);

        return ['order' => $placed->id, 'number' => $placed->number, 'customer' => $ready['customer'], 'variants' => $ready['variants']];
    }

    private static function staff(): string
    {
        $staff = Fx::staffWith([
            SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::PAYMENT_RECORD, SalesPermissions::RETURN_MANAGE, SalesPermissions::ORDER_CANCEL,
        ], ['sa']);
        Fx::actAsStaff($staff);

        return $staff;
    }
}

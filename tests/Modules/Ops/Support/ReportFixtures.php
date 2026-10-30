<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ops\Application\Query\DownloadReport\DownloadReport;
use Modules\Ops\Application\Query\DownloadReport\DownloadReportHandler;
use Modules\Ops\Application\Query\DownloadReport\ReportFile;
use Modules\Ops\Application\Query\ViewReport\ReportPreview;
use Modules\Ops\Application\Query\ViewReport\ViewReport;
use Modules\Ops\Application\Query\ViewReport\ViewReportHandler;
use Modules\Ops\Application\Reports\ReportMoney;
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
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

/**
 * The reports' tests (ops.md §1.10, §8): a day of a store's sales made through Sales' own use cases - an
 * order delivered (two of the 100.00 size, one of the 50.00), with one piece of the 100.00 size returned and
 * refunded and one of the 50.00 still waiting; an order cancelled; an order rejected - and the reports read
 * back, as the preview gives them or from the Excel file itself.
 */
final class ReportFixtures
{
    /**
     * @return array{delivered: string, cancelled: string, rejected: string, number: string, variants: list<string>, product: string, done: string, open: string}
     */
    public static function aDay(string $storeCode = 'sa'): array
    {
        $delivered = self::order($storeCode);
        $staff = self::staff($storeCode);
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

        $cancelled = self::order($storeCode);
        self::staff($storeCode);
        app(CancelOrderHandler::class)->handle(new CancelOrder($cancelled['order'], 'The customer called'));
        $rejected = self::order($storeCode);
        self::staff($storeCode);
        app(RejectOrderHandler::class)->handle(new RejectOrder($rejected['order'], 'Out of the delivery area'));

        return [
            'delivered' => $delivered['order'],
            'cancelled' => $cancelled['order'],
            'rejected' => $rejected['order'],
            'number' => $delivered['number'],
            'variants' => $delivered['variants'],
            'product' => $delivered['product'],
            'done' => $done,
            'open' => $open,
        ];
    }

    /** A report's preview, as the person acting reads it. */
    public static function preview(string $report, string $store, ?string $from = null, ?string $to = null, ?string $by = null): ReportPreview
    {
        return app(ViewReportHandler::class)->handle(new ViewReport($report, $store, $from, $to, $by));
    }

    /**
     * A preview's sheet as plain values: an amount as its decimal string.
     *
     * @return list<list<string|int|null>>
     */
    public static function rows(ReportPreview $preview, int $sheet = 0): array
    {
        $rows = [];
        foreach ($preview->report->sheets[$sheet]->rows as $row) {
            $rows[] = array_map(static fn (string|int|ReportMoney|null $value): string|int|null => $value instanceof ReportMoney ? $value->decimal() : $value, $row);
        }

        return $rows;
    }

    /**
     * The Excel file of a report, read back with OpenSpout's own reader - each sheet's name and rows as
     * Excel holds them, empty rows kept - and the file deleted.
     *
     * @return array{filename: string, sheets: array<string, list<list<mixed>>>, xml: array<string, string>}
     */
    public static function download(string $report, string $store, ?string $from = null, ?string $to = null, ?string $by = null): array
    {
        return self::read(app(DownloadReportHandler::class)->handle(new DownloadReport($report, $store, $from, $to, $by)));
    }

    /**
     * A report's file read back, and deleted.
     *
     * @return array{filename: string, sheets: array<string, list<list<mixed>>>, xml: array<string, string>}
     */
    public static function read(ReportFile $file): array
    {
        try {
            $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
            $reader->open($file->path);
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    // A row as written: Excel's padding to the sheet's width left off.
                    $values = $row->toArray();
                    while ($values !== [] && end($values) === '') {
                        array_pop($values);
                    }
                    $rows[] = array_values($values);
                }
                $sheets[$sheet->getName()] = $rows;
            }
            $reader->close();

            $zip = new \ZipArchive;
            $zip->open($file->path);
            $xml = [];
            for ($index = 1; ($part = $zip->getFromName("xl/worksheets/sheet{$index}.xml")) !== false; $index++) {
                $xml["sheet{$index}"] = $part;
            }
            $xml['styles'] = (string) $zip->getFromName('xl/styles.xml');
            $zip->close();
        } finally {
            @unlink($file->path);
        }

        return ['filename' => $file->filename, 'sheets' => $sheets, 'xml' => $xml];
    }

    /** @return array{order: string, number: string, customer: string, variants: list<string>, product: string} */
    private static function order(string $storeCode): array
    {
        $ready = Cx::ready(storeCode: $storeCode);
        $placed = Cx::place(Cx::start($ready['address'], storeCode: $storeCode)->id, $storeCode);

        return ['order' => $placed->id, 'number' => $placed->number, 'customer' => $ready['customer'], 'variants' => $ready['variants'], 'product' => $ready['product']];
    }

    private static function staff(string $storeCode): string
    {
        $staff = Fx::staffWith([
            SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::PAYMENT_RECORD, SalesPermissions::RETURN_MANAGE, SalesPermissions::ORDER_CANCEL,
        ], [$storeCode]);
        Fx::actAsStaff($staff);

        return $staff;
    }
}

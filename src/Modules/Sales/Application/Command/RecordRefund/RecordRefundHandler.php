<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RecordRefund;

use Modules\Sales\Application\Order\MoneyRecording;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\SalesError;
use Modules\Sales\Domain\Model\MoneyRecord;
use Shared\Application\Unauthorized;

/** Recording a refund by hand (sales.md §1.9): never above what the order holds — through `MoneyRecording`. */
final readonly class RecordRefundHandler
{
    public const string PERMISSION = SalesPermissions::PAYMENT_RECORD;

    public function __construct(
        private OrderAccess $access,
        private MoneyRecording $money,
    ) {}

    /**
     * @return string the record's id
     *
     * @throws Unauthorized|SalesError
     */
    public function handle(RecordRefund $command): string
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        return $this->money->record(MoneyRecord::REFUND, $orderId, $staffId, $command->amount, $command->way, $command->reference, $command->note, $command->happenedOn, $command->requestId);
    }
}

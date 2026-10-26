<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RecordPayment;

use Modules\Sales\Application\Order\MoneyRecording;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\SalesError;
use Modules\Sales\Domain\Model\MoneyRecord;
use Shared\Application\Unauthorized;

/** Recording a payment by hand (sales.md §1.9): the whole balance still owed, never typed — through `MoneyRecording`. */
final readonly class RecordPaymentHandler
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
    public function handle(RecordPayment $command): string
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        return $this->money->record(MoneyRecord::PAYMENT, $orderId, $staffId, null, $command->way, $command->reference, $command->note, $command->happenedOn, $command->requestId);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Sales\Domain\Model\MoneyRecord;

/** The payments and refunds recorded against orders (sales.md §1.9): written once, voided once at most. */
interface MoneyRecords
{
    /** The record a form's request id made already, of either kind; null when none. */
    public function byRequest(string $kind, string $requestId): ?MoneyRecord;

    public function add(MoneyRecord $record): void;

    /** The order a record belongs to; null when there is no such record. */
    public function orderOf(string $kind, string $recordId): ?string;

    /** The record, its row locked until the transaction ends. */
    public function lock(string $kind, string $recordId): ?MoneyRecord;

    public function void(string $kind, string $recordId, string $by, string $reason, DateTimeImmutable $at): void;
}

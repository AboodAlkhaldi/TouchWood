<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Contracts;

use DateTimeImmutable;
use Modules\Sales\Public\Dto\MoneyDaysDto;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Modules\Sales\Public\Dto\ReturnMessageDto;
use Modules\Sales\Public\Dto\SalesCountsDto;
use Modules\Sales\Public\Dto\TransfersWaitingDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Sales offers Ops (sales.md §2.5; ops.md §1.5, §1.11, §2.3) — its own contract, apart from `SalesApi`
 * (Feedback's). Reads only, with no permission of their own: the caller asks who may see them. Ids in, DTOs
 * out; amounts in the order's or the store's currency, never converted.
 */
interface SalesReports
{
    /**
     * **An order as its messages need it**: number, state, lines and totals as sold (or as edited), way to
     * pay, carrier and number, the reasons staff gave — names and values in `locale` (`ar`; anything else
     * reads as English). Null for none. The tracking link comes with stage 7's Shipping.
     */
    public function orderForMessage(string $orderId, string $locale): ?OrderMessageDto;

    /** **A return as its messages need it**: its order's number, state, the store's words when refused, the refund once completed. Null for none. */
    public function returnForMessage(string $returnId): ?ReturnMessageDto;

    /**
     * **The daily report's orders** (ops.md §1.11 item 2) from `from` up to `to` (not included): placed —
     * individuals and companies apart — cancelled, rejected, come back. One query.
     */
    public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): SalesCountsDto;

    /**
     * **The money moved** (ops.md §1.11 item 3), by the day it moved (`happened_on`, the store's own days)
     * from `fromDay` to `toDay`, both included — payments in and refunds out, by way; voided records never.
     * Two queries.
     */
    public function moneyOn(StoreId $store, DateTimeImmutable $fromDay, DateTimeImmutable $toDay): MoneyDaysDto;

    /**
     * **The bank transfers still awaited** (ops.md §1.11 item 3): open orders to be paid by transfer that
     * hold less than they owe, and how much. Overdue ones are stage 7's mark (sales.md §9.5 #9).
     */
    public function transfersWaiting(StoreId $store): TransfersWaitingDto;
}

<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use DateTimeImmutable;
use Modules\Payments\Domain\Model\PaymentRequest;
use Modules\Payments\Public\Enums\PaymentRequestState;

interface PaymentRequests
{
    /** False when the order already has an open one (the unique index, one per order). */
    public function add(PaymentRequest $request): bool;

    /** The request, its row locked to the end of the transaction. */
    public function lock(string $id): ?PaymentRequest;

    public function openOf(string $orderId): ?PaymentRequest;

    /** The order's open request, its row locked to the end of the transaction. */
    public function lockOpenOf(string $orderId): ?PaymentRequest;

    /** Ends it, only while it is open: one withdrawn or expired stays as it is, whatever is answered later. */
    public function end(string $id, PaymentRequestState $state, DateTimeImmutable $now): void;
}

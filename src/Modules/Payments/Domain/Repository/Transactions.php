<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\TransactionFacts;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Enums\PaymentMode;

interface Transactions
{
    /**
     * Whether this gateway payment is already written: true when succeeded, false when written as declined,
     * null when not written at all.
     */
    public function succeeded(PaymentMode $mode, string $gatewayPaymentId): ?bool;

    /**
     * Writes it, or turns a declined one into a success (rule 5). Answers its id. Called under the attempt's lock.
     */
    public function record(Attempt $attempt, TransactionFacts $facts): string;

    /** One transaction as other modules read it; null when there is no such thing. */
    public function dto(string $id): ?PaymentDto;
}

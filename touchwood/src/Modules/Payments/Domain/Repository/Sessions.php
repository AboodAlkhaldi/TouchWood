<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use DateTimeImmutable;
use Modules\Payments\Domain\Model\GatewaySession;
use Modules\Payments\Public\Enums\PaymentMode;

interface Sessions
{
    public function add(GatewaySession $session): void;

    /** The session, its row locked to the end of the transaction. */
    public function lock(PaymentMode $mode, string $id): ?GatewaySession;

    public function markUsed(PaymentMode $mode, string $id, string $attemptId): void;

    /** The newest session of this owner and buyer, for this amount and mode, unused and not over. */
    public function reusable(string $storeId, PaymentMode $mode, string $ownerId, string $buyerId, int $amountMinor, string $currencyCode, DateTimeImmutable $now): ?GatewaySession;
}

<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Repository;

use DateTimeImmutable;
use Modules\Loyalty\Domain\Model\RedemptionHold;

/**
 * The points held for online payments (loyalty.md §9.6). Written under the account's lock, as every
 * points change is.
 */
interface RedemptionHolds
{
    /** The try's hold, or null. With `$lock`, its row is locked to the end of the transaction. */
    public function of(string $orderId, bool $lock = false): ?RedemptionHold;

    /** How many points the account holds for payments now. */
    public function heldBy(string $accountId): int;

    /**
     * The account's live points now that no hold keeps: what may be quoted, spent, held again or deducted
     * - each hold keeps the points its confirm will spend, from the lots live when it was made.
     */
    public function freePoints(string $accountId, DateTimeImmutable $now): int;

    public function add(RedemptionHold $hold): void;

    /** The try's hold goes; false when there was none. */
    public function drop(string $orderId): bool;
}

<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Sales\Domain\Model\Quote;

/** The checkout's quotes (sales.md §1.2): written once, read by their id, deleted once expired. */
interface Quotes
{
    public function add(Quote $quote): void;

    /** The quote, whoever's it is; null when there is none. */
    public function find(string $quoteId): ?Quote;

    /** As `find`, its row locked until the transaction ends — placing it (§1.3 step 1). */
    public function lock(string $quoteId): ?Quote;

    /** Those expired and never placed. @return int how many were deleted */
    public function deleteExpiredBefore(DateTimeImmutable $at): int;
}

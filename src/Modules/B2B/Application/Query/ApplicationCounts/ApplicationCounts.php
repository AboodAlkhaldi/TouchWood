<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ApplicationCounts;

use DateTimeImmutable;
use Modules\B2B\Public\Dto\ApplicationCountsDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * One store's applications sent and approved in a range, for Ops's daily report (b2b.md amendment
 * 34), read in one query.
 */
interface ApplicationCounts
{
    /**
     * From `$from` up to but not including `$to`; the caller has checked that `$to` is not before
     * `$from`.
     */
    public function in(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): ApplicationCountsDto;
}

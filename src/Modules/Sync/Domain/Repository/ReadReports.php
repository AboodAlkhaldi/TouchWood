<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

use Modules\Sync\Domain\ValueObject\ReportKind;

/**
 * What each read reported, as it was that day (sync.md §9.3 #19; owner, 2026-10-18: exact per read) -
 * kept with the read, deleted with it. The report's entries are the store's present; this is the
 * read's past.
 */
interface ReadReports
{
    /**
     * @param  list<array{code: string|null, variantId: string|null, saleId: string|null, detail: array<string, mixed>}>  $entries
     */
    public function add(string $readId, ReportKind $kind, array $entries): void;
}

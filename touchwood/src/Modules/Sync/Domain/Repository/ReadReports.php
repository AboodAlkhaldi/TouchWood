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

    /** What the read reported forgotten: an attempt tried again reports it all again. */
    public function clear(string $readId): void;

    /** The sizes it skipped: codes we do not have, and sizes archived or of a bad value, once each. */
    public function skipped(string $readId): int;
}

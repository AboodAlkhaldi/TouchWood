<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\StockHistory;

/**
 * A page of a size's history: its lines, newest first, and the cursor of the next page (none on the last).
 */
final readonly class HistoryPage
{
    /**
     * @param  list<HistoryLine>  $lines
     */
    public function __construct(
        public array $lines,
        public ?string $next,
    ) {}
}

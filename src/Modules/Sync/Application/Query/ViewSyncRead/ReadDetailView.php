<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

use Modules\Sync\Application\Query\ListSyncReads\ReadView;
use Modules\Sync\Application\Report\ReportedView;

/**
 * One read and its sections (sync.md §9.3 #19): prices changed, stock changed, reported - each a page of
 * at most `perPage`, with its total - and the codes a notice's or a code's read asked for.
 */
final readonly class ReadDetailView
{
    /**
     * @param  list<string>|null  $codes
     * @param  list<ReadLineView>  $prices
     * @param  list<ReadLineView>  $stock
     * @param  list<ReportedView>  $reported
     */
    public function __construct(
        public ReadView $read,
        public ?array $codes,
        public array $prices,
        public int $pricesTotal,
        public array $stock,
        public int $stockTotal,
        public array $reported,
        public int $reportedTotal,
        public int $page,
        public int $perPage,
    ) {}
}

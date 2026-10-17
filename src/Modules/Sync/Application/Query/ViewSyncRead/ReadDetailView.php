<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

use Modules\Sync\Application\Query\ListSyncReads\ReadView;
use Modules\Sync\Application\Report\ReportEntryView;

/**
 * One read and its sections (sync.md §9.3 #19): prices changed, stock changed, reported.
 */
final readonly class ReadDetailView
{
    /**
     * @param  list<ReadLineView>  $prices
     * @param  list<ReadLineView>  $stock
     * @param  list<ReportEntryView>  $reported
     */
    public function __construct(
        public ReadView $read,
        public array $prices,
        public array $stock,
        public array $reported,
    ) {}
}

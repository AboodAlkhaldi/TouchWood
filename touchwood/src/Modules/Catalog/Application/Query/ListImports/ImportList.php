<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListImports;

use Modules\Catalog\Application\Import\ImportSummary;

/**
 * One page of uploaded files, newest first.
 */
final readonly class ImportList
{
    /**
     * @param  list<ImportSummary>  $imports
     */
    public function __construct(
        public array $imports,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}

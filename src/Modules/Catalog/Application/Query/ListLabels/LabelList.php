<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListLabels;

use Modules\Catalog\Application\Query\Lists\LabelRow;

final readonly class LabelList
{
    /**
     * @param  list<LabelRow>  $labels
     */
    public function __construct(
        public array $labels,
        public bool $mayChange,
    ) {}
}

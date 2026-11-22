<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListWarranties;

use Modules\Catalog\Application\Query\Lists\WarrantyRow;

final readonly class WarrantyList
{
    /**
     * @param  list<WarrantyRow>  $warranties
     */
    public function __construct(
        public array $warranties,
        public bool $mayChange,
    ) {}
}

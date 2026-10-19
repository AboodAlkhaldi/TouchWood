<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\AddSizeChoices;

use Modules\Inventory\Application\Query\StockViews\SizeView;

/**
 * A page of Add Size's picker (Q24): every ready product's sizes not listed in the store yet, by product
 * name then the size's place, the next page's cursor and the total - and, for a search, the first few
 * sizes it finds that **are** listed already, which the picker shows as "Already listed", opening the
 * size's row instead.
 */
final readonly class SizeChoices
{
    /**
     * @param  list<SizeView>  $rows
     * @param  list<SizeView>  $alreadyListed
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
        public array $alreadyListed,
    ) {}
}

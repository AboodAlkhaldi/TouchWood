<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListSearchesWithNoResults;

use Modules\Catalog\Application\Query\Lists\NoResultSearchRow;

final readonly class NoResultSearchList
{
    /**
     * @param  list<NoResultSearchRow>  $searches
     */
    public function __construct(
        public array $searches,
        public int $page,
        public bool $more,
    ) {}
}

<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListWordPairs;

use Modules\Catalog\Application\Query\Lists\WordPairRow;

/**
 * The word pairs, whether the reader may add and delete them, and whether they may read the
 * searches that found nothing — both the job with All stores (§3).
 */
final readonly class WordPairList
{
    /**
     * @param  list<WordPairRow>  $pairs
     */
    public function __construct(
        public array $pairs,
        public bool $mayChange,
    ) {}
}

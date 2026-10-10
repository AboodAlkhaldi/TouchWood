<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * A shared word pair (catalog.md §1.11), as search reads its words.
 */
final readonly class WordPairRow
{
    public function __construct(
        public string $id,
        public string $wordA,
        public string $wordB,
    ) {}
}

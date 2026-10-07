<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListWordPairs;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ListReaders;
use Shared\Application\Unauthorized;

/**
 * **The search words screen's pairs** (catalog.md §4.4 S7), read as every list is (P2).
 */
final readonly class ListWordPairsHandler
{
    public const string PERMISSION = CatalogPermissions::SEARCH_WORD_MANAGE;

    public function __construct(
        private ListReaders $readers,
        private CatalogListReads $reads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListWordPairs $query): WordPairList
    {
        $mayChange = $this->readers->authorize(self::PERMISSION);

        return new WordPairList($this->reads->wordPairs(), $mayChange);
    }
}

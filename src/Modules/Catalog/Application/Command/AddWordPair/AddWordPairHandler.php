<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddWordPair;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\NameTaken;
use Modules\Catalog\Domain\Model\WordPair;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\WordPairRepository;
use Shared\Application\Unauthorized;

/**
 * **Adding a shared word pair** (catalog.md §1.11), under `catalog.search_word.manage` with All
 * stores: one entry for the whole shop. A pair already in the list, in either order, is refused
 * (`NameTaken`) — asked under the pairs' lock, with the database's unique index behind it.
 */
final readonly class AddWordPairHandler
{
    public const string PERMISSION = CatalogPermissions::SEARCH_WORD_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private WordPairRepository $pairs,
    ) {}

    /**
     * @return string the new pair's id
     *
     * @throws InvalidCatalogAttribute|NameTaken|Unauthorized
     */
    public function handle(AddWordPair $command): string
    {
        $this->change->authorize(self::PERMISSION);
        $pair = WordPair::add($this->pairs->nextId(), $command->one, $command->other);

        return $this->change->run(ListLocks::WORD_PAIRS, function () use ($pair): array {
            if ($this->pairs->exists($pair)) {
                throw new NameTaken('word_pair');
            }

            $this->pairs->add($pair);

            return [$pair->id, [ListAudit::added('word_pair', $pair->id, ['word_a' => $pair->wordA, 'word_b' => $pair->wordB])]];
        });
    }
}

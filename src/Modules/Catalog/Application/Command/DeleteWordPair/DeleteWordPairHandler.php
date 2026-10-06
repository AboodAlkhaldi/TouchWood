<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteWordPair;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\WordPairRepository;
use Shared\Application\Unauthorized;

/**
 * **Deleting a shared word pair** (catalog.md §1.11): nothing carries one, so it always goes.
 */
final readonly class DeleteWordPairHandler
{
    public const string PERMISSION = CatalogPermissions::SEARCH_WORD_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private WordPairRepository $pairs,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeleteWordPair $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::WORD_PAIRS, function () use ($command): array {
            $pair = $this->pairs->find($command->pairId) ?? throw new ListItemNotFound($command->pairId);
            $this->pairs->delete($pair->id);

            return [null, [ListAudit::deleted('word_pair', $pair->id, ['word_a' => $pair->wordA, 'word_b' => $pair->wordB])]];
        });
    }
}

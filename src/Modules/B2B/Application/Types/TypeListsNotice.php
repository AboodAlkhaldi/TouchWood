<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Types;

use Modules\B2B\Domain\Repository\StoreTypeListsRepository;

/**
 * The "copied from the Saudi store" notice on a store's types page (b2b.md §1.3, amendment 6(a)):
 * **any change to either of the store's lists clears it** (amendment 10(d)) — an admin who edited
 * the lists has reviewed them. Called inside the change's own transaction, after the store's
 * type-list lock.
 */
final readonly class TypeListsNotice
{
    public function __construct(
        private StoreTypeListsRepository $lists,
    ) {}

    /**
     * @return bool whether the notice was showing and is now cleared
     */
    public function clear(string $storeId): bool
    {
        $lists = $this->lists->byStore($storeId);

        if ($lists === null) {
            return false;
        }

        $lists->markReviewed();

        if ($lists->pullChanges() === []) {
            return false;
        }

        $this->lists->update($lists);

        return true;
    }
}

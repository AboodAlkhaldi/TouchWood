<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Domain\Repository\CategoryAudienceRepository;
use Modules\Catalog\Domain\ValueObject\CategoryAudience;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * **A sub-category added, or moved, under a parent takes that parent's switches in every store**
 * (catalog.md §1.13, P24) — as switching the parent would have given it: otherwise one added later
 * under a parent switched off for individuals would show to them ("no row means both"). Called by
 * adding and moving a category, under the categories' lock, inside their own transaction; each
 * store's change audited in that store, as switching it there is.
 *
 * Moved to the top, a category has no parent to take switches from, and keeps its own — my pick
 * (README): nothing it hid shows by surprise.
 */
final readonly class CategoryAudiences
{
    public function __construct(
        private CategoryAudienceRepository $audiences,
    ) {}

    /**
     * @param  list<string>  $categoryIds  the category added or moved, and everything under it
     * @return list<AuditEntryDto>
     */
    public function takeParents(string $parentId, array $categoryIds): array
    {
        $rows = $this->audiences->inEveryStore([$parentId, ...$categoryIds]);
        $entries = [];

        foreach ($rows as $storeId => $inStore) {
            // A store where nothing of the parent or the branch was ever switched changes nothing.
            $parent = $inStore[$parentId] ?? CategoryAudience::both();
            $changed = [];

            foreach ($categoryIds as $categoryId) {
                $was = $inStore[$categoryId] ?? CategoryAudience::both();

                if ($was->equals($parent)) {
                    continue;
                }

                $changed[$categoryId] = $parent;
                $entry = ListAudit::changed('category', 'audience_set', $categoryId, $was->changedIn($parent), $parent->snapshot(), (string) $storeId);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            $this->audiences->set((string) $storeId, $changed);
        }

        return $entries;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderCategories;

use InvalidArgumentException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\ListOrder;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A store's order of one parent's children** (catalog.md §1.5, §3, amendment 16(d)), under
 * `catalog.category.rank` in that store: the tree is every store's, the order each store's own. As
 * every list's order (P29): the ids sent first, every other sibling after them in the order the
 * store's menu shows now — its own place, else the base store's (amendment 5(a)) —, each written
 * again 1, 2, 3 … as the store's own, so a page left open never undoes a move made since on rows it
 * did not send. Under the categories' lock; an id not among the parent's children is not found.
 * Each category whose place changed is audited in that store; an order that moves nothing records
 * nothing.
 */
final readonly class OrderCategoriesHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_RANK;

    public function __construct(
        private Authorizer $authorizer,
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws CategoryNotFound|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(OrderCategories $command): void
    {
        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized(self::PERMISSION);
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));

        // Only now, and only for someone who may change it, is the store looked up; a store that is
        // off is ordered before it opens (amendment 4(f)), as RankCategories orders it.
        if ($this->platform->store($store) === null) {
            throw new InvalidCatalogAttribute('store', 'a store');
        }

        $sent = ListOrder::sent($command->categoryIds);
        $parentId = $command->parentId === null || trim($command->parentId) === '' ? null : strtolower(trim($command->parentId));

        $this->change->run(ListLocks::CATEGORIES, function () use ($store, $parentId, $sent): array {
            if ($parentId !== null && $this->categories->find($parentId) === null) {
                throw new CategoryNotFound($parentId);
            }

            $siblings = $this->categories->siblingsIn($store->value, $this->input->baseStoreId(), $parentId);
            // Compared by its own place: one following the base store's gets its own with the order,
            // so the store's menu no longer moves when the base store's does.
            $own = array_map(static fn (array $sibling): int => $sibling[0] ?? 0, $siblings);
            $changes = ListOrder::changes($own, $sent, static fn (string $id): CategoryNotFound => new CategoryNotFound($id));
            $this->categories->placeEach($store->value, ListOrder::positions($changes));
            $entries = [];

            foreach (ListOrder::moved($changes) as $id => [, $now]) {
                $entries[] = ListAudit::changed('category', 'ranked', $id, ['rank' => $siblings[$id][0]], ['rank' => $now], $store->value);
            }

            return [null, array_values(array_filter($entries))];
        });
    }
}

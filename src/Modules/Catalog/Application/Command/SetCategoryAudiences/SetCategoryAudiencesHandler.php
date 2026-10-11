<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetCategoryAudiences;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\CategoryAudienceRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Who sees a category in a store** (catalog.md §1.13, §3, amendment 14): its Individuals and
 * Companies switches, under `catalog.category.audience` **in that store** — a job only an admin role
 * holds (14(c), declared `adminOnly`). A store that is off is prepared before it opens, as its menu
 * order is.
 *
 * **Switching a category switches everything under it the same way** (14(d)): the switch flipped is
 * written to the category and to each category below it, at any depth; the other switch stays as each
 * has it, so a sub-category switched on its own keeps what it was given. A product is seen by a kind
 * of account where its own category is on for it, so **the listing rows of the products in the
 * categories that changed are written again**, in the same transaction (§8 #34).
 *
 * The products' lock first — the listing is written under it — then the categories', so the tree
 * read below does not change meanwhile. Each category whose switches changed is audited in that store;
 * nothing changed, nothing is written or recorded.
 */
final readonly class SetCategoryAudiencesHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_AUDIENCE;

    public function __construct(
        private StoreListingChange $change,
        private CategoryRepository $categories,
        private CategoryAudienceRepository $audiences,
        private ProductRepository $products,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws CategoryNotFound|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(SetCategoryAudiences $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        if ($command->forIndividuals === null && $command->forCompanies === null) {
            throw new InvalidCatalogAttribute('audience', 'a switch for individuals or for companies');
        }

        $this->change->runAfterProducts(ListLocks::CATEGORIES, function () use ($command, $store): array {
            $category = $this->categories->find($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);
            $ids = [$category->id(), ...$this->categories->idsBelow($category->id())];
            $changed = [];
            $entries = [];

            foreach ($this->audiences->in($store->value, $ids) as $categoryId => $was) {
                $now = $was->with($command->forIndividuals, $command->forCompanies);

                if ($now->equals($was)) {
                    continue;
                }

                $changed[$categoryId] = $now;
                $entry = ListAudit::changed('category', 'audience_set', $categoryId, $was->changedIn($now), $now->snapshot(), $store->value);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            if ($changed === []) {
                return [null, []];
            }

            $this->audiences->set($store->value, $changed);
            $this->listingRows->refresh($this->products->idsInCategories(array_keys($changed)));

            return [null, $entries];
        });
    }
}

<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Modules\Catalog\Public\Enums\CatalogItem;
use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a category, brand, product or variant another module still uses (amendment 19;
 * promotions.md §1.2): a coupon's include or exclude list, a gift level. Refused, never a rule dropped
 * silently. The caller reads which modules, how many uses, and the first item used — when a batch is
 * deleted (the import's "delete all"), that names the product to look at.
 */
final class CatalogItemInUse extends CatalogError
{
    /**
     * @param  list<string>  $modules  the modules that reported a use, or the table a foreign key named
     */
    public function __construct(
        public readonly array $modules,
        public readonly int $uses,
        public readonly CatalogItem $item,
        public readonly string $itemId,
    ) {
        parent::__construct("The {$item->value} {$itemId} is used elsewhere (".implode(', ', $modules)."): {$uses} use(s); remove them first.");
    }

    public function type(): string
    {
        return 'catalog.item_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['modules' => implode(',', $this->modules), 'uses' => $this->uses, 'item' => $this->item->value, 'item_id' => $this->itemId];
    }
}

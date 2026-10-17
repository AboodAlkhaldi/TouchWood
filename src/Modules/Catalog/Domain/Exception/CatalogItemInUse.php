<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a category, brand, product or variant another module still uses (amendment 19;
 * promotions.md §1.2): a coupon's include or exclude list, a gift level. Refused, never a rule dropped
 * silently; the caller reads which modules and how many uses.
 */
final class CatalogItemInUse extends CatalogError
{
    /**
     * @param  list<string>  $modules
     */
    public function __construct(public readonly array $modules, public readonly int $uses)
    {
        parent::__construct('Used elsewhere ('.implode(', ', $modules)."): {$uses} use(s); remove them first.");
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
        return ['modules' => implode(',', $this->modules), 'uses' => $this->uses];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Selling terms, labels or "Not available now" for a product — or a variant — the store has never
 * chosen (catalog.md §1.3, §7, amendment 4(e)). A store that switched it off since keeps its rows,
 * and may still change them.
 */
final class NotChosenInStore extends CatalogError
{
    public function __construct()
    {
        parent::__construct('This store has not chosen that product.');
    }

    public function type(): string
    {
        return 'catalog.not_chosen_in_store';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}

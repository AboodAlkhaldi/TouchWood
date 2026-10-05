<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A product file or a store file not in its format (catalog.md §1.12, §1.3; the guide in
 * docs/modules/catalog-import/): refused whole, **every problem listed** — where it is (the product
 * or item, counted from 1, and the field) and what the field must be — so the file is fixed once,
 * not one error at a time. Nothing of a refused file is kept.
 */
final class ImportRefused extends CatalogError
{
    /**
     * @param  list<array{at: string, problem: string}>  $problems
     */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct('The file is not in its format: '.count($problems).' problem(s).');
    }

    public function type(): string
    {
        return 'catalog.import_refused';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    /** How many; the problems themselves are `$problems`, for the page that lists them. */
    public function context(): array
    {
        return ['count' => count($this->problems)];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A product's selling terms in a store that cannot hold (catalog.md §1.3, §7): a variant with no
 * selling mode, a maximum below its minimum, wholesale on with no wholesale minimum. The rule broken
 * is named in the context.
 */
final class InvalidSellingTerms extends CatalogError
{
    public function __construct(
        public readonly string $rule,
    ) {
        parent::__construct("Selling terms must keep: {$rule}.");
    }

    public function type(): string
    {
        return 'catalog.invalid_selling_terms';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['rule' => $this->rule];
    }
}

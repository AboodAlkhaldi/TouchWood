<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * What "Apply in this store" starts the form with (b2b.md amendment 19(b)): the name of the company
 * in another store, and its type's counterpart in this store's list — or its "Other" words, or no
 * type at all. Everything else is entered fresh.
 */
final readonly class PrefillView
{
    public function __construct(
        public string $name,
        public ?string $companyTypeId,
        public ?string $companyTypeOther,
        /** The store the name comes from. */
        public string $fromStoreId,
    ) {}
}

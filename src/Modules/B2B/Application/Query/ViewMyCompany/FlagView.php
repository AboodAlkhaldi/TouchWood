<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * One item a rejection marked to be replaced (b2b.md §1.2, amendment 4): a field, or the paper
 * under a document type — exactly one of the two.
 */
final readonly class FlagView
{
    public function __construct(
        /** name, company_type, cr_number, tax_number or address. */
        public ?string $field,
        public ?string $documentTypeId,
    ) {}
}

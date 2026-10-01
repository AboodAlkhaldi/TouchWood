<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * The values an application holds (b2b.md §1.2) — a draft's, or what one sent. A listed type comes
 * with its names, read from the home store's whole list, so a type since hidden is still named.
 */
final readonly class ApplicationValues
{
    public function __construct(
        public ?string $name,
        public ?string $companyTypeId,
        public ?string $companyTypeNameAr,
        public ?string $companyTypeNameEn,
        public ?string $companyTypeOther,
        public ?string $crNumber,
        public ?string $taxNumber,
        public ?string $address,
        /** The saved address it was picked from, while that address exists; null for older text (amendment 16(f)). */
        public ?string $addressId,
        public ?string $note,
    ) {}
}

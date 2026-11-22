<?php

declare(strict_types=1);

namespace Modules\B2B\Public\Dto;

use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * A company, as other modules see it (b2b.md §2.1). **Never its documents**: a paper is reached only
 * through its own signed link, by staff with the permission (§3).
 */
final readonly class CompanyDto
{
    public function __construct(
        public string $id,
        public string $customerId,
        public string $name,
        /**
         * The type's name from the home store's list. **Both null while the company is still
         * "Other"**: its type is not set yet, and its own words are for the reviewing staff alone
         * (amendment 13(b)).
         */
        public ?string $typeNameAr,
        public ?string $typeNameEn,
        public CompanyStatus $status,
        /** Why it was rejected or suspended; null otherwise. */
        public ?string $statusReason,
        /** The store it applied in, and the one store it may order in once approved (amendment 18). */
        public string $storeId,
    ) {}
}

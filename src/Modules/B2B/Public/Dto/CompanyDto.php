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
        /** The type's name from the home store's list; both null when the company wrote its own ("Other"). */
        public ?string $typeNameAr,
        public ?string $typeNameEn,
        /** The company's own words for its type when it chose "Other"; null for a listed type. */
        public ?string $typeOther,
        public CompanyStatus $status,
        /** Why it was rejected or suspended; null otherwise. */
        public ?string $statusReason,
    ) {}
}

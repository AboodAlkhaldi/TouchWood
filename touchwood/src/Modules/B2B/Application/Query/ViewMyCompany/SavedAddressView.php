<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * One of the account's saved addresses, as the company form offers it (b2b.md §4.5, amendment
 * 16(f)): any store's, written in its store's format. One the format no longer accepts is shown but
 * cannot be picked.
 */
final readonly class SavedAddressView
{
    public function __construct(
        public string $id,
        public string $storeNameAr,
        public string $storeNameEn,
        public string $label,
        public string $formatted,
        public bool $isComplete,
    ) {}
}

<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An address in a country another of our stores ships to, saved in this one: refused when the
 * address is saved, pointing to that store (shipping.md §1.2, §9.3 #1; access.md amendment 76(c)). An
 * off store's countries still count as its own.
 */
final class AddressCountryServedByAnotherStore extends AccessError
{
    public function __construct(
        public readonly string $countryCode,
        public readonly string $storeCode,
        public readonly string $storeNameAr,
        public readonly string $storeNameEn,
    ) {
        parent::__construct("Addresses in \"{$countryCode}\" are served by the store \"{$storeCode}\".");
    }

    public function type(): string
    {
        return 'access.address_country_served_by_another_store';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        // The code for the link to that store; its name for the words, in each language.
        return ['country' => $this->countryCode, 'store' => $this->storeCode, 'store_ar' => $this->storeNameAr, 'store_en' => $this->storeNameEn];
    }
}

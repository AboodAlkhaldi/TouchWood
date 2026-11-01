<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The address is in a country another of our stores ships to (sales.md §9.5 #2; shipping.md §1.2
 * `OTHER_STORE`): refused, naming that store, so the customer orders from there.
 */
final class AddressInOtherStore extends SalesError
{
    public function __construct(
        public readonly string $storeId,
        public readonly string $storeCode,
        public readonly string $storeAr,
        public readonly string $storeEn,
    ) {
        parent::__construct('The address is served by another store.');
    }

    public function type(): string
    {
        return 'sales.address_in_other_store';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['storeCode' => $this->storeCode, 'storeAr' => $this->storeAr, 'storeEn' => $this->storeEn];
    }
}

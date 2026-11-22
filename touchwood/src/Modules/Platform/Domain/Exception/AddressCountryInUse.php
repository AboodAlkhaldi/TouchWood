<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Retiring an address country a store ships to: refused until it is off every store's list (stage 7 step 1, Q2).
 */
final class AddressCountryInUse extends PlatformError
{
    public function __construct(public readonly string $countryCode, public readonly string $storeCode)
    {
        parent::__construct("The country \"{$countryCode}\" cannot be retired: the store \"{$storeCode}\" ships there.");
    }

    public function type(): string
    {
        return 'platform.address_country_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['country' => $this->countryCode, 'store' => $this->storeCode];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A country taken off the address list: nothing new is added in it until a Super Admin restores it (stage 7 step 1, Q3).
 */
final class AddressCountryRetired extends PlatformError
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct("The country \"{$countryCode}\" is retired from the address list.");
    }

    public function type(): string
    {
        return 'platform.address_country_retired';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['country' => $this->countryCode];
    }
}

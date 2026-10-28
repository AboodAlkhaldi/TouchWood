<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A country that is not on the address list (platform.md, stage 7 (a)).
 */
final class AddressCountryNotFound extends PlatformError
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct("The country \"{$countryCode}\" is not on the address list.");
    }

    public function type(): string
    {
        return 'platform.address_country_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['country' => $this->countryCode];
    }
}

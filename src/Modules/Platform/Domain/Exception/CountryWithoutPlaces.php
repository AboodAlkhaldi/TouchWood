<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Adding a ship-to country Platform has no place list for (shipping.md §1.3, §7): its carriers could never be priced. A list means at least one city in use (stage 7 step 1, Q5).
 */
final class CountryWithoutPlaces extends PlatformError
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct("The country \"{$countryCode}\" has no place list yet.");
    }

    public function type(): string
    {
        return 'platform.country_without_places';
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

<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A country already on another store's ship-to list: a country ships from one store at most (owner, 2026-10-27 R3). Move it off that store first (stage 7 step 1, Q1).
 */
final class CountryShippedByAnotherStore extends PlatformError
{
    public function __construct(public readonly string $countryCode, public readonly string $storeCode)
    {
        parent::__construct("The country \"{$countryCode}\" is shipped to by the store \"{$storeCode}\".");
    }

    public function type(): string
    {
        return 'platform.country_shipped_by_another_store';
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

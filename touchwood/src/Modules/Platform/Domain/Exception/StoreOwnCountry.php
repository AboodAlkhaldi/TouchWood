<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Taking a store's own country off its ship-to list: it is always on it (shipping.md §1.3).
 */
final class StoreOwnCountry extends PlatformError
{
    public function __construct(public readonly string $countryCode, public readonly string $storeCode)
    {
        parent::__construct("The store \"{$storeCode}\" always ships to its own country \"{$countryCode}\".");
    }

    public function type(): string
    {
        return 'platform.store_own_country';
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

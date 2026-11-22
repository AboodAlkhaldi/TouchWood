<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a currency a store uses, on or off: a store's currency never changes (§1.1), so the
 * currency stays as long as the store does (platform.md §9.7, owner 2026-10-04).
 */
final class CurrencyInUse extends PlatformError
{
    public function __construct(public readonly string $currencyCode)
    {
        parent::__construct("The currency \"{$currencyCode}\" cannot be deleted: a store uses it.");
    }

    public function type(): string
    {
        return 'platform.currency_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['code' => $this->currencyCode];
    }
}

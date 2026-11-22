<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Opening a store with a currency another store already uses: one currency, one store (platform.md
 * §1.2, §9.7 #4; owner, 2026-10-06).
 */
final class CurrencyTaken extends PlatformError
{
    public function __construct(public readonly string $currencyCode)
    {
        parent::__construct("The currency \"{$currencyCode}\" is already another store's.");
    }

    public function type(): string
    {
        return 'platform.currency_taken';
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

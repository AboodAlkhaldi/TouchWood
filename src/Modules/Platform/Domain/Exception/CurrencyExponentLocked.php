<?php

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class CurrencyExponentLocked extends PlatformError
{
    public function __construct(public readonly string $currencyCode)
    {
        parent::__construct("The exponent of \"{$currencyCode}\" cannot change: a store already uses this currency.");
    }

    public function type(): string
    {
        return 'platform.currency_exponent_locked';
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

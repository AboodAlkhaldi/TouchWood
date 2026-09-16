<?php

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class CurrencyAlreadyExists extends PlatformError
{
    public function __construct(public readonly string $currencyCode)
    {
        parent::__construct("The currency \"{$currencyCode}\" already exists.");
    }

    public function type(): string
    {
        return 'platform.currency_already_exists';
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

<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class CurrencyNotFound extends PlatformError
{
    public function __construct(public readonly string $currencyCode)
    {
        parent::__construct("No currency has the code \"{$currencyCode}\".");
    }

    public function type(): string
    {
        return 'platform.currency_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['code' => $this->currencyCode];
    }
}

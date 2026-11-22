<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class InvalidTimezone extends PlatformError
{
    public function __construct(public readonly string $timezone)
    {
        parent::__construct("\"{$timezone}\" is not a valid IANA timezone.");
    }

    public function type(): string
    {
        return 'platform.invalid_timezone';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['timezone' => $this->timezone];
    }
}

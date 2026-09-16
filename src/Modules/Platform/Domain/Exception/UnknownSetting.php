<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class UnknownSetting extends PlatformError
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("No module declares a setting named \"{$key}\".");
    }

    public function type(): string
    {
        return 'platform.unknown_setting';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['key' => $this->key];
    }
}

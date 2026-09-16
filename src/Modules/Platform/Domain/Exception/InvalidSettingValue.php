<?php

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class InvalidSettingValue extends PlatformError
{
    public function __construct(public readonly string $key, public readonly string $reason)
    {
        parent::__construct("Invalid value for \"{$key}\": {$reason}");
    }

    public function type(): string
    {
        return 'platform.invalid_setting_value';
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

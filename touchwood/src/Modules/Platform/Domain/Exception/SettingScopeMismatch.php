<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class SettingScopeMismatch extends PlatformError
{
    public function __construct(public readonly string $key, public readonly string $expected)
    {
        parent::__construct("The setting \"{$key}\" is {$expected}.");
    }

    public function type(): string
    {
        return 'platform.setting_scope_mismatch';
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

<?php

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class InvalidStoreAttribute extends PlatformError
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid store {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'platform.invalid_store_attribute';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}

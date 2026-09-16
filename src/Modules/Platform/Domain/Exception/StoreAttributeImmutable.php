<?php

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class StoreAttributeImmutable extends PlatformError
{
    public function __construct(public readonly string $attribute)
    {
        parent::__construct("A store's {$attribute} cannot be changed after it is created.");
    }

    public function type(): string
    {
        return 'platform.store_attribute_immutable';
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

<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class InvalidMediaAttribute extends PlatformError
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid media {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'platform.invalid_media_attribute';
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

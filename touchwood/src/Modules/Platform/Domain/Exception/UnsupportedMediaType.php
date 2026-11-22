<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class UnsupportedMediaType extends PlatformError
{
    public function __construct(public readonly string $mime, public readonly string $visibility)
    {
        parent::__construct("A {$visibility} file cannot be of type \"{$mime}\".");
    }

    public function type(): string
    {
        return 'platform.unsupported_media_type';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unsupported;
    }

    public function context(): array
    {
        return ['mime' => $this->mime];
    }
}

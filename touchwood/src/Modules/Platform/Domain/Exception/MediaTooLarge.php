<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class MediaTooLarge extends PlatformError
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The file is too large: {$reason}.");
    }

    public function type(): string
    {
        return 'platform.media_too_large';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::TooLarge;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}

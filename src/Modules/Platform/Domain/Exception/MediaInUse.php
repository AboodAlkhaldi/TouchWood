<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class MediaInUse extends PlatformError
{
    public function __construct(public readonly string $mediaId)
    {
        parent::__construct("Media \"{$mediaId}\" is still used and cannot be deleted.");
    }

    public function type(): string
    {
        return 'platform.media_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['id' => $this->mediaId];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class MediaNotFound extends PlatformError
{
    public function __construct(public readonly string $mediaId)
    {
        parent::__construct("No media has the id \"{$mediaId}\".");
    }

    public function type(): string
    {
        return 'platform.media_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['id' => $this->mediaId];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A region, city or district id that names no place.
 */
final class PlaceNotFound extends PlatformError
{
    public function __construct(public readonly string $placeId)
    {
        parent::__construct("No place has the id \"{$placeId}\".");
    }

    public function type(): string
    {
        return 'platform.place_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['id' => $this->placeId];
    }
}

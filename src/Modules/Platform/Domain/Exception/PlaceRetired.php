<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Adding under a retired region or city: it would be hidden from every picker at once (stage 7 step 1, Q4).
 */
final class PlaceRetired extends PlatformError
{
    public function __construct(public readonly string $placeId)
    {
        parent::__construct("The place \"{$placeId}\" is retired.");
    }

    public function type(): string
    {
        return 'platform.place_retired';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['id' => $this->placeId];
    }
}

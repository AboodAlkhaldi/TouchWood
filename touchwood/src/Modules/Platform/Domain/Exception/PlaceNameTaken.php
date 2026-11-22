<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Two places of one kind under one parent with the same name, in either language (stage 7 step 1, Q6).
 */
final class PlaceNameTaken extends PlatformError
{
    public function __construct(public readonly string $name)
    {
        parent::__construct("Another place here is already called \"{$name}\".");
    }

    public function type(): string
    {
        return 'platform.place_name_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['name' => $this->name];
    }
}

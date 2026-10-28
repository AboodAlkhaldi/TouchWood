<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A place whose details break its rules: a name too long, or a parent of the wrong kind or country.
 */
final class InvalidPlace extends PlatformError
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid place {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'platform.invalid_place';
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

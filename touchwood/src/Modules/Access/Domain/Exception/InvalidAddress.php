<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A field the store's address format refuses: missing, too long, or not one of its fields at all
 * (spec §1.9, amendment 41).
 */
final class InvalidAddress extends AccessError
{
    public function __construct(public readonly string $field, public readonly string $reason)
    {
        parent::__construct("The address field \"{$field}\" is {$reason}.");
    }

    public function type(): string
    {
        return 'access.invalid_address';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['field' => $this->field];
    }
}

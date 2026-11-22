<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value Ops refuses (ops.md §7): an inbox entry's text empty or too long, a link that leaves the
 * site, a kind given to the wrong person, a place in a list that is not one.
 */
final class InvalidOpsInput extends OpsError
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'ops.invalid_ops_input';
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

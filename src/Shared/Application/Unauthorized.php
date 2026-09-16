<?php

declare(strict_types=1);

namespace Shared\Application;

use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;

final class Unauthorized extends DomainError
{
    public function __construct(
        public readonly string $permission,
    ) {
        parent::__construct("The current actor does not hold the permission \"{$permission}\".");
    }

    public function type(): string
    {
        return 'shared.unauthorized';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}

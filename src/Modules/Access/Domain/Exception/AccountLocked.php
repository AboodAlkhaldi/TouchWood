<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Too many wrong passwords, for this account or from this address: try again later (spec §1.8).
 */
final class AccountLocked extends AccessError
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Too many wrong passwords. Try again in {$retryAfterSeconds} seconds.");
    }

    public function type(): string
    {
        return 'access.account_locked';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }

    public function context(): array
    {
        return ['minutes' => (int) ceil($this->retryAfterSeconds / 60)];
    }
}

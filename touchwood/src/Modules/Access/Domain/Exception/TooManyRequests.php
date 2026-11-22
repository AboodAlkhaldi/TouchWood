<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Too many registrations or reset requests from one address (owner, 2026-09-20: ten an hour). It
 * says nothing about any account: the limit is on the connection, not on a person.
 */
final class TooManyRequests extends AccessError
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Too many requests from this address. Try again in {$retryAfterSeconds} seconds.");
    }

    public function type(): string
    {
        return 'access.too_many_requests';
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

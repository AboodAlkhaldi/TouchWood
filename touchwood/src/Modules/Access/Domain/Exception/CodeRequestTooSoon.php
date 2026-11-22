<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A new SMS code asked for too soon, or too many in the last hour (spec §1.3).
 */
final class CodeRequestTooSoon extends AccessError
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("A new code can be sent in {$retryAfterSeconds} seconds.");
    }

    public function type(): string
    {
        return 'access.code_request_too_soon';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['seconds' => $this->retryAfterSeconds];
    }
}

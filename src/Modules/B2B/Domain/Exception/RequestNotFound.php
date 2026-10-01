<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Answering a request that is not one of the last rejection's (b2b.md §3.1, §7, amendment 5): the
 * last application sent was not rejected, made no such request, or there is none.
 */
final class RequestNotFound extends B2BError
{
    public function __construct(public readonly string $requestId = '')
    {
        parent::__construct("No request \"{$requestId}\" in the last decision.");
    }

    public function type(): string
    {
        return 'b2b.request_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}

<?php

declare(strict_types=1);

namespace Shared\Domain\Error;

/**
 * The fixed list of kinds of business error. The mapping to HTTP statuses lives only in
 * App\Http\ProblemDetails.
 */
enum ErrorCategory: string
{
    case NotFound = 'NOT_FOUND';
    case Forbidden = 'FORBIDDEN';
    case Conflict = 'CONFLICT';
    case Invalid = 'INVALID';
    case Unsupported = 'UNSUPPORTED';
    case TooLarge = 'TOO_LARGE';

    /** An outside service did not answer in time; trying again later may work (platform.md, stage 7). */
    case Unavailable = 'UNAVAILABLE';
}

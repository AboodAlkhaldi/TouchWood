<?php

namespace Shared\Domain\Error;

/**
 * The fixed list of kinds of business error. The mapping to HTTP statuses lives only in
 * Shared\Infrastructure\Http\ProblemDetails.
 */
enum ErrorCategory: string
{
    case NotFound = 'NOT_FOUND';
    case Forbidden = 'FORBIDDEN';
    case Conflict = 'CONFLICT';
    case Invalid = 'INVALID';
    case Unsupported = 'UNSUPPORTED';
    case TooLarge = 'TOO_LARGE';
}

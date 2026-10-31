<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The app step opened too many times in an hour for one account - the same limit as SMS codes sent to
 * one number (amendment 91).
 */
final class AppSignInsTooSoon extends AccessError
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Too many sign-ins with an app code; try again in {$retryAfterSeconds} seconds.");
    }

    public function type(): string
    {
        return 'access.app_sign_ins_too_soon';
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

<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Removing, resetting or renewing the codes of an app that is not set up (amendment 91).
 */
final class NoAuthenticator extends AccessError
{
    public function __construct()
    {
        parent::__construct('No authenticator app is set up.');
    }

    public function type(): string
    {
        return 'access.no_authenticator';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}

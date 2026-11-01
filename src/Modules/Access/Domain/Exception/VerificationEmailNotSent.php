<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A customer asked for a new verification link and its email failed (amendment 93). Nothing was
 * saved, and the press is not counted toward the hourly limit.
 */
final class VerificationEmailNotSent extends AccessError
{
    public function __construct()
    {
        parent::__construct('The verification link could not be sent.');
    }

    public function type(): string
    {
        return 'access.verification_email_not_sent';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A setup whose QR code is no longer good: more than 15 minutes after the password, too many wrong codes, or none started in this browser (amendment 91).
 */
final class AuthenticatorSetupExpired extends AccessError
{
    public function __construct()
    {
        parent::__construct('This setup has ended. Give your password again for a new QR code.');
    }

    public function type(): string
    {
        return 'access.authenticator_setup_expired';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}

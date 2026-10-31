<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

/**
 * How a staff member proves the second step of signing in (amendment 91): a code by SMS, or a code
 * from their authenticator app.
 */
enum SignInMethod: string
{
    case Sms = 'SMS';
    case Authenticator = 'AUTHENTICATOR';
}

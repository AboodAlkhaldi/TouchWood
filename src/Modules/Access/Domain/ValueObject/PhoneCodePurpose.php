<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

/**
 * What a staff phone code verifies. Signing in has its own codes (step 3b).
 */
enum PhoneCodePurpose: string
{
    /** The phone of an invitation being accepted. */
    case Accept = 'ACCEPT';

    /** A staff member's own new phone. */
    case Change = 'CHANGE';
}

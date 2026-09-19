<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

/**
 * What a staff SMS code is for. Sign-in codes are kept in their own table (spec §5.3).
 */
enum PhoneCodePurpose: string
{
    /** The phone of an invitation being accepted. */
    case Accept = 'ACCEPT';

    /** A staff member's own new phone. */
    case Change = 'CHANGE';

    /** The second step of signing in; it also verifies a number not verified yet. */
    case SignIn = 'SIGN_IN';

    /**
     * The purposes stored with the phone-verifying codes, rather than with sign-in codes.
     *
     * @return list<self>
     */
    public static function verifyingPhone(): array
    {
        return [self::Accept, self::Change];
    }
}

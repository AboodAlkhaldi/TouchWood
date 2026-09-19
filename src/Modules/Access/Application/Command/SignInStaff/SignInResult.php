<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignInStaff;

/**
 * Where the right password leads.
 */
enum SignInResult: string
{
    /** A trusted browser: signed in, no code asked. */
    case SignedIn = 'SIGNED_IN';

    /** An SMS code went to their phone. */
    case CodeSent = 'CODE_SENT';

    /** A Super Admin whose phone was reset enters a new number first (amendment 14). */
    case PhoneNeeded = 'PHONE_NEEDED';
}

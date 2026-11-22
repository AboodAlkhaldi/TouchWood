<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/** What a code is for (ops.md §9.8 point 1): the `log` driver words it differently, and nothing else does. */
enum CodePurpose
{
    /** A phone being verified: a customer's, or a staff member's. */
    case PhoneCheck;

    /** A staff member signing in, right after the password. */
    case SignIn;
}

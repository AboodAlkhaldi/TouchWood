<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/** Why a line left the cart at sign-in or a merge (sales.md §1.1). */
enum CartNoticeReason: string
{
    /** A wholesale line, and the account is an individual's (answer 32). */
    case Wholesale = 'WHOLESALE';
    /** A product whose category is closed to the account. */
    case NotForYourAccount = 'NOT_FOR_YOUR_ACCOUNT';
}

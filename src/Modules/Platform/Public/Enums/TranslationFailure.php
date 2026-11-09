<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * Why a machine translation failed (content.md §2.2, §1.3), as the person is told: each reason has its
 * own words in the module that shows it, never the service's own text.
 */
enum TranslationFailure: string
{
    /** This month's allowance is used up (DeepL's 456). */
    case Quota = 'QUOTA';

    /** Too many requests to the service right now (429, 529): trying again in a minute may work. */
    case Busy = 'BUSY';

    /** The service refused the call: a bad token (401, 403), or a call it would not take (400 and the rest). */
    case Refused = 'REFUSED';

    /** No usable answer: a timeout, no connection, the service's own failure (5xx), or an answer that was not one. */
    case Unreachable = 'UNREACHABLE';
}

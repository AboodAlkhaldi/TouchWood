<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * What the account is told before there is a company (b2b.md §4.3): not company statuses — they
 * are the absence of a company — and company prices are shown in all three (§1.1).
 */
enum AccountStage: string
{
    /** "Confirm your email" — Access's own banner (frontend.md F4). */
    case EmailNotConfirmed = 'EMAIL_NOT_CONFIRMED';

    /** "Continue your application": confirmed, and nothing started. */
    case NoApplication = 'NO_APPLICATION';

    /** "Finish and submit your application": a draft exists. */
    case DraftOpen = 'DRAFT_OPEN';
}

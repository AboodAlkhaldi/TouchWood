<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ApproveCompany;

/**
 * Which type an approved company carries when the application's type was deactivated after it was
 * sent (b2b.md §1.3, amendment 10(e)).
 */
enum ApprovalTypeChoice: string
{
    /** The type the deactivation gave the company, when staff replaced it. */
    case Replacement = 'REPLACEMENT';

    /** The type sent, for this company alone — it stays deactivated for every new application. */
    case Keep = 'KEEP';

    /** Another type, under the usual correction rules. */
    case Correct = 'CORRECT';
}

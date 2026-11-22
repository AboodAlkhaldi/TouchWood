<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;
use Throwable;

/**
 * No code can be sent right now (ops.md §9.8 W3): WhatsApp is not set up, or Meta refused the setup itself - a missing
 * setting, a bad token, a template that is not approved. The site goes on; whoever asked for a code is told "Codes
 * can't be sent right now. Try again later." (`ops::errors.codes_unavailable`), never what Meta said: the
 * cause is for the Super Admins, by their bell and email, and for the log.
 */
final class CodesUnavailable extends OpsError
{
    public function __construct(?Throwable $cause = null)
    {
        parent::__construct('Codes cannot be sent right now: WhatsApp is not set up or refused the setup.', 0, $cause);
    }

    public function type(): string
    {
        return 'ops.codes_unavailable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}

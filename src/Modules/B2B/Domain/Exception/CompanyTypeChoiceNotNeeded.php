<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Approving with a type choice when none is needed — the type was activated again since the
 * reviewer saw the warning (b2b.md §3.2, §7, amendment 10(i)). Refused rather than ignored, so the
 * reviewer decides again with the facts as they are now; nothing is written.
 */
final class CompanyTypeChoiceNotNeeded extends B2BError
{
    public function __construct()
    {
        parent::__construct('The application\'s company type is active again: no type choice is needed.');
    }

    public function type(): string
    {
        return 'b2b.company_type_choice_not_needed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}

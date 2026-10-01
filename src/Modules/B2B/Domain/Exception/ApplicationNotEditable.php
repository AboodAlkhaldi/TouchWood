<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A change to an application that is no longer a draft (b2b.md §4.2, §7): once sent it is the
 * staff's, and a decided one is never reopened.
 */
final class ApplicationNotEditable extends B2BError
{
    public function __construct(public readonly string $state = '')
    {
        parent::__construct("The application cannot be changed: it is {$state}.");
    }

    public function type(): string
    {
        return 'b2b.application_not_editable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}

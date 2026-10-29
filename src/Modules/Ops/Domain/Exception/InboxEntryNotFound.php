<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown inbox entry, or one that is not the reader's - another person's, or of a store their bell
 * does not show: the same answer for all (ops.md §1.2, §3: own only).
 */
final class InboxEntryNotFound extends OpsError
{
    public function __construct()
    {
        parent::__construct('No such notification.');
    }

    public function type(): string
    {
        return 'ops.inbox_entry_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}

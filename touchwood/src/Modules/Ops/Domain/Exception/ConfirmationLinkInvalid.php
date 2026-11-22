<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A newsletter confirmation link that confirms nothing (ops.md §1.9): unknown, used already, or older
 * than the seven days an unconfirmed sign-up is kept. The same answer for all.
 */
final class ConfirmationLinkInvalid extends OpsError
{
    public function __construct()
    {
        parent::__construct('This confirmation link is not valid any more.');
    }

    public function type(): string
    {
        return 'ops.confirmation_link_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}

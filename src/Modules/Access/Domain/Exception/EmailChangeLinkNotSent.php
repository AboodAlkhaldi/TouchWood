<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The link for a staff member's new address could not be emailed (amendment 93). The change **is
 * saved**: the transaction had committed, and only the email failed. Asking again replaces the link.
 */
final class EmailChangeLinkNotSent extends AccessError
{
    public function __construct()
    {
        parent::__construct('The change is saved, but the link could not be sent to the new address.');
    }

    public function type(): string
    {
        return 'access.email_change_link_not_sent';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}

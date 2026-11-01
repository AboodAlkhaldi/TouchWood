<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The invitation's email could not be sent (amendment 93). Unlike every other refusal, what was asked
 * **is saved**: the transaction had committed, and only the email failed. An exception so that no
 * caller can say the invitation went by forgetting to look. The provider's failure itself is already
 * in the log; this one, like every DomainError, is not reported.
 */
final class InvitationNotSent extends AccessError
{
    public function __construct(public readonly string $staffId)
    {
        parent::__construct('The account is saved, but the invitation email could not be sent.');
    }

    public function type(): string
    {
        return 'access.invitation_not_sent';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ConfirmStaffEmailChange;

/**
 * The link sent to a new staff email was used: it is now the staff member's email (amendment 17).
 */
final readonly class ConfirmStaffEmailChange
{
    public function __construct(
        public string $token,
    ) {}
}

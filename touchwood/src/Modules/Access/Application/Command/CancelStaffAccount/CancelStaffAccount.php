<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelStaffAccount;

/**
 * Someone invited who never accepted: their account is cancelled for good and their email and
 * phone are free for a new invitation (amendment 29).
 */
final readonly class CancelStaffAccount
{
    public function __construct(
        public string $staffId,
    ) {}
}

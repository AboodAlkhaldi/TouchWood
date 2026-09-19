<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeOwnStaffPassword;

/**
 * A staff member's own new password: this session stays, every other one and every trusted browser
 * ends (spec §1.8).
 */
final readonly class ChangeOwnStaffPassword
{
    public function __construct(
        public string $currentPassword,
        public string $newPassword,
    ) {}
}

<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RenewRecoveryCodes;

/**
 * Ten new recovery codes in place of every old one, after the current password (amendment 91).
 */
final readonly class RenewRecoveryCodes
{
    public function __construct(
        #[\SensitiveParameter]
        public string $currentPassword,
        public string $ip,
    ) {}
}

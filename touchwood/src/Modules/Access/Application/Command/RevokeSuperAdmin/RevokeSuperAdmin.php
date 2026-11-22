<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RevokeSuperAdmin;

/**
 * Only from the server's console (spec §1.6). The person keeps an account with no role until an
 * admin gives them one. At least one active Super Admin must remain (amendment 18).
 */
final readonly class RevokeSuperAdmin
{
    public function __construct(
        public string $email,
    ) {}
}

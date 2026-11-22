<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Revoking the last active Super Admin would lock the business out (spec §1.6, amendment 18).
 */
final class LastSuperAdmin extends AccessError
{
    public function __construct()
    {
        parent::__construct('This is the last active Super Admin and cannot be revoked.');
    }

    public function type(): string
    {
        return 'access.last_super_admin';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}

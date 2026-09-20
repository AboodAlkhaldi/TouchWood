<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignOutCustomer;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Session\CustomerSessions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Signing out ends this session only (spec §1.8); a customer signs out under their own permission
 * (amendment 2).
 */
final readonly class SignOutCustomerHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_SIGN_OUT;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerSessions $sessions,
    ) {}

    public function handle(SignOutCustomer $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->sessions->end();
    }
}

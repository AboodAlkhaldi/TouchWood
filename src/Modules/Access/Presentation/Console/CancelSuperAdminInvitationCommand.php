<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Access\Application\Command\CancelSuperAdminInvitation\CancelSuperAdminInvitation;
use Modules\Access\Application\Command\CancelSuperAdminInvitation\CancelSuperAdminInvitationHandler;
use Shared\Domain\Error\DomainError;

final class CancelSuperAdminInvitationCommand extends Command
{
    protected $signature = 'access:super-admin:cancel {email : The invited Super Admin\'s email}';

    protected $description = 'Cancel a Super Admin who has not accepted; their email and phone are free again';

    public function handle(CancelSuperAdminInvitationHandler $handler): int
    {
        try {
            $handler->handle(new CancelSuperAdminInvitation($this->argument('email')));
        } catch (DomainError $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('Cancelled. The email and phone can be used for a new invitation.');

        return self::SUCCESS;
    }
}

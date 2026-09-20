<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Access\Application\Command\ResendSuperAdminInvitation\ResendSuperAdminInvitation;
use Modules\Access\Application\Command\ResendSuperAdminInvitation\ResendSuperAdminInvitationHandler;
use Shared\Domain\Error\DomainError;

final class ResendSuperAdminInvitationCommand extends Command
{
    protected $signature = 'access:super-admin:resend-invitation {email : The invited Super Admin\'s email}';

    protected $description = 'Send a new invitation to a Super Admin who has not accepted; the earlier link dies';

    public function handle(ResendSuperAdminInvitationHandler $handler): int
    {
        try {
            $handler->handle(new ResendSuperAdminInvitation($this->argument('email')));
        } catch (DomainError $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('A new invitation was emailed; it works for the set Super Admin invitation hours.');

        return self::SUCCESS;
    }
}

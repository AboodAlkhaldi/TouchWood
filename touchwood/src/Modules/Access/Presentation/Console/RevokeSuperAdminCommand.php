<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Access\Application\Command\RevokeSuperAdmin\RevokeSuperAdmin;
use Modules\Access\Application\Command\RevokeSuperAdmin\RevokeSuperAdminHandler;
use Shared\Domain\Error\DomainError;

final class RevokeSuperAdminCommand extends Command
{
    protected $signature = 'access:super-admin:revoke {email : The Super Admin\'s email}';

    protected $description = 'Take Super Admin away; the last active one cannot be revoked';

    public function handle(RevokeSuperAdminHandler $handler): int
    {
        try {
            $handler->handle(new RevokeSuperAdmin($this->argument('email')));
        } catch (DomainError $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('No longer a Super Admin. The account is disabled until an admin enables it together with a role.');

        return self::SUCCESS;
    }
}

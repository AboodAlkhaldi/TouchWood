<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhone;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhoneHandler;
use Shared\Domain\Error\DomainError;

final class ResetSuperAdminPhoneCommand extends Command
{
    protected $signature = 'access:super-admin:reset-phone {email : The Super Admin\'s email}';

    protected $description = 'Remove a Super Admin\'s lost phone; they verify a new one at their next sign-in';

    public function handle(ResetSuperAdminPhoneHandler $handler): int
    {
        try {
            $handler->handle(new ResetSuperAdminPhone($this->argument('email')));
        } catch (DomainError $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('Phone removed. At the next sign-in, after the password, a new number is entered and verified by SMS code.');

        return self::SUCCESS;
    }
}

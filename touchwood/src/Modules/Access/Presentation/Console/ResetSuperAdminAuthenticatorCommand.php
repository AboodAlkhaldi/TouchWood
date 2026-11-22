<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Access\Application\Command\ResetSuperAdminAuthenticator\ResetSuperAdminAuthenticator;
use Modules\Access\Application\Command\ResetSuperAdminAuthenticator\ResetSuperAdminAuthenticatorHandler;
use Shared\Domain\Error\DomainError;

final class ResetSuperAdminAuthenticatorCommand extends Command
{
    protected $signature = 'access:super-admin:reset-authenticator {email : The Super Admin\'s email}';

    protected $description = 'Remove a Super Admin\'s lost authenticator app and recovery codes; they sign in by SMS and set a new one up';

    public function handle(ResetSuperAdminAuthenticatorHandler $handler): int
    {
        try {
            $handler->handle(new ResetSuperAdminAuthenticator($this->argument('email')));
        } catch (DomainError $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('Authenticator app removed. At the next sign-in, after the password, an SMS code is asked; then a new app is set up when it is their method.');

        return self::SUCCESS;
    }
}

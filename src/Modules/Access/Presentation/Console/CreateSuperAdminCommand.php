<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Shared\Domain\Error\DomainError;

/**
 * The only way a Super Admin is made (spec §1.6): on the server, never in the panel.
 */
final class CreateSuperAdminCommand extends Command
{
    private const array PROFILE_OPTIONS = ['job-title', 'date-of-birth', 'country', 'phone', 'address'];

    protected $signature = 'access:super-admin:create
        {email : The Super Admin\'s email; an existing staff member with it is promoted and keeps their profile}
        {first_name? : First name (new accounts)}
        {last_name? : Last name (new accounts)}
        {--job-title= : Job title (new accounts)}
        {--date-of-birth= : YYYY-MM-DD (new accounts)}
        {--country= : ISO 3166-1 alpha-2 country code, e.g. SA (new accounts)}
        {--phone= : International phone number, e.g. +966501234567 (new accounts)}
        {--locale=ar : Communication language, ar or en (new accounts)}
        {--address= : Address (optional, new accounts)}';

    protected $description = 'Create a Super Admin and email their invitation, or promote an existing staff member';

    public function handle(CreateSuperAdminHandler $handler): int
    {
        try {
            $result = $handler->handle(new CreateSuperAdmin(
                $this->argument('email'),
                $this->argument('first_name'),
                $this->argument('last_name'),
                $this->optionText('job-title'),
                $this->optionText('date-of-birth'),
                $this->optionText('country'),
                $this->optionText('phone'),
                $this->optionText('locale') ?? 'ar',
                $this->optionText('address'),
            ));
        } catch (DomainError $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if ($result === CreateSuperAdminHandler::CREATED) {
            $this->info('Super Admin created; the invitation was emailed (valid for the set invitation hours).');

            return self::SUCCESS;
        }

        $this->info('The existing staff member is now a Super Admin.');

        $given = $this->argument('first_name') !== null || $this->argument('last_name') !== null
            || array_filter(self::PROFILE_OPTIONS, fn (string $option): bool => $this->option($option) !== null) !== [];

        if ($given) {
            $this->warn('The profile values were ignored: an existing staff member keeps their profile.');
        }

        return self::SUCCESS;
    }

    private function optionText(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : null;
    }
}

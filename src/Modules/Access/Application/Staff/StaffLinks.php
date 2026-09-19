<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

/**
 * The links in staff emails. They lead to the admin area's pages, which the frontend stage builds;
 * the token is the link's only secret.
 */
interface StaffLinks
{
    public function invitation(string $token): string;

    public function emailChange(string $token): string;

    public function passwordReset(string $token): string;
}

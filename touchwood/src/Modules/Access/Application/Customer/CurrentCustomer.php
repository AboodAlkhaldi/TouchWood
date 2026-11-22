<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Modules\Access\Application\Permission\AccessPermissions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Unauthorized;

/**
 * The customer this request acts as. Their own account's use cases take the id from here, never
 * from the request, so nobody edits another account by sending its id (spec §1.5, §3.1).
 */
final readonly class CurrentCustomer
{
    public function __construct(
        private ActorContext $actors,
    ) {}

    /**
     * @param  string  $permission  the one the caller was about to check, so the refusal names the
     *                              action that was refused
     *
     * @throws Unauthorized when a guest, a staff member or the system is acting
     */
    public function id(string $permission = AccessPermissions::ACCOUNT_UPDATE): string
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        return $actor->id;
    }
}

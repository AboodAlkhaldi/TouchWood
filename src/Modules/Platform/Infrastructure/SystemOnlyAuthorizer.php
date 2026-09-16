<?php

namespace Modules\Platform\Infrastructure;

use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Interim binding until Access exists (build stage 2): only the system may act. It denies
 * any staff member or customer, so nothing is accidentally open before roles exist.
 * Access replaces this binding with the real permission check.
 */
final readonly class SystemOnlyAuthorizer implements Authorizer
{
    public function __construct(
        private ActorContext $actors,
    ) {}

    public function authorize(string $permission, ?StoreId $store = null): void
    {
        if ($this->actors->current()->type !== ActorType::System) {
            throw new Unauthorized($permission);
        }
    }
}

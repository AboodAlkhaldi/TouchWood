<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Interim binding until Access exists (build stage 2): only the system may act, and only
 * outside web requests — console commands, seeders, scheduled tasks and queued jobs. With no
 * login yet, SystemActorContext reports the system for a web request too, so web requests are
 * refused here: nothing is accidentally open before roles exist.
 * Access replaces this binding with the real permission check.
 */
final readonly class SystemOnlyAuthorizer implements Authorizer
{
    /**
     * @param  bool  $runningInConsole  false while serving a web request
     */
    public function __construct(
        private ActorContext $actors,
        private bool $runningInConsole,
    ) {}

    public function authorize(string $permission, ?StoreId $store = null): void
    {
        if (! $this->runningInConsole || $this->actors->current()->type !== ActorType::System) {
            throw new Unauthorized($permission);
        }
    }
}

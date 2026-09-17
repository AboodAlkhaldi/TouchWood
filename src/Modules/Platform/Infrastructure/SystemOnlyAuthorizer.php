<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

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

    public function authorize(string $permission, PermissionScope $scope): void
    {
        if (! $this->isSystem()) {
            throw new Unauthorized($permission);
        }
    }

    /**
     * The system acts in every store, so it is never limited to a list.
     */
    public function storesWith(string $permission): ?array
    {
        return $this->isSystem() ? null : [];
    }

    private function isSystem(): bool
    {
        return $this->runningInConsole && $this->actors->current()->type === ActorType::System;
    }
}

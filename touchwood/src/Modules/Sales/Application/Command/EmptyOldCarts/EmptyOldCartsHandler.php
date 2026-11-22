<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\EmptyOldCarts;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Carts no one touched for 90 days, emptied each night (sales.md §1.1, my assumption, accepted with the
 * spec): the system's reserved `sales.system.run` — no person runs it.
 */
final readonly class EmptyOldCartsHandler
{
    public const string PERMISSION = SalesPermissions::SYSTEM_RUN;

    public const int DAYS = 90;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Carts $carts,
    ) {}

    /**
     * @return int how many carts were emptied
     *
     * @throws Unauthorized
     */
    public function handle(EmptyOldCarts $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::System || $actor->requestedBy !== null) {
            throw new Unauthorized(self::PERMISSION);
        }

        return $this->carts->deleteUntouchedSince(CarbonImmutable::now()->subDays(self::DAYS));
    }
}

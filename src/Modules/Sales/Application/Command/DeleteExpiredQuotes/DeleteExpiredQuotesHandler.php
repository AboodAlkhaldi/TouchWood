<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\DeleteExpiredQuotes;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Repository\Quotes;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Quotes past their expiry go, nightly (sales.md §5) — the system's only; a placed one stays with its
 * order.
 */
final readonly class DeleteExpiredQuotesHandler
{
    public const string PERMISSION = SalesPermissions::SYSTEM_RUN;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Quotes $quotes,
    ) {}

    /**
     * @return int how many quotes were deleted
     *
     * @throws Unauthorized
     */
    public function handle(DeleteExpiredQuotes $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::System || $actor->requestedBy !== null) {
            throw new Unauthorized(self::PERMISSION);
        }

        return $this->quotes->deleteExpiredBefore(CarbonImmutable::now());
    }
}

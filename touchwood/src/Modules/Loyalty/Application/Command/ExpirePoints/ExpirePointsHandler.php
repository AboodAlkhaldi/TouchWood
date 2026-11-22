<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\ExpirePoints;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Psr\Log\LoggerInterface;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Throwable;

/**
 * Expires every account's dead lots (loyalty.md §1.10): the nightly pass over all stores, as the
 * system (`loyalty.points.expire`, reserved). **One account at a time, each in its own transaction
 * under its own lock** — the same `lockOrCreate` every write uses, which expires the account's dead
 * lots first — so a night's sweep never holds two accounts at once, and a write that reached an
 * account first leaves nothing for it. An account that fails is logged and passed over, so it never
 * keeps the others waiting (as Access's sweep). Not audited: the ledger is its record (§3).
 */
final readonly class ExpirePointsHandler
{
    /** Reserved, and the system's alone. */
    public const string PERMISSION = LoyaltyPermissions::EXPIRE;

    /** Accounts read per page, in id order. */
    public const int PAGE = 500;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PointsAccounts $accounts,
        private ConnectionInterface $db,
        private LoggerInterface $log,
        private int $page = self::PAGE,
    ) {}

    /**
     * @return int how many accounts had points expire
     *
     * @throws Unauthorized
     */
    public function handle(ExpirePoints $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // The system only (§3): a Super Admin holds every reserved permission, yet the sweep is the
        // schedule's own work — never theirs, nor a job queued on their behalf (as Access's sweep).
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::System || $actor->requestedBy !== null) {
            throw new Unauthorized(self::PERMISSION);
        }

        // One moment for the whole pass: a lot that dies while it runs waits for the next write.
        $now = CarbonImmutable::now();
        $expired = 0;
        $after = null;

        do {
            $page = $this->accounts->withDeadLots($now, $after, $this->page);

            foreach ($page as $account) {
                $after = $account->id;

                try {
                    $expired += $this->sweep($account, $now) ? 1 : 0;
                } catch (Throwable $failure) {
                    $this->log->error('A points account could not be swept.', ['account' => $account->id, 'exception' => $failure]);
                }
            }
        } while (count($page) === $this->page);

        return $expired;
    }

    /** Whether the account lost points: a write may have swept it since the page was read. */
    private function sweep(PointsAccount $account, CarbonImmutable $now): bool
    {
        $after = $this->db->transaction(fn (): PointsAccount => $this->accounts->lockOrCreate($account->customerId, $account->store, $now), 3);

        return $after->balance < $account->balance;
    }
}

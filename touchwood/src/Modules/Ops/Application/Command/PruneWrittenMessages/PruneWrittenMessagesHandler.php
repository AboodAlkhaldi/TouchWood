<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\PruneWrittenMessages;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Newsletter\NewsletterSignUps;
use Modules\Ops\Application\OpsPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The nightly removal** of what staff wrote and of unconfirmed sign-ups (ops.md §5; owner, 2026-10-28,
 * 2026-11-01): staff messages and notices sent, cancelled or failed keep twelve months; a guest who never
 * confirmed is deleted seven days after signing up - nothing of theirs ever reached the provider. One still
 * waiting or sending is never removed. System maintenance under the reserved `ops.log.prune`, as
 * PruneOpsLog: it audits nothing.
 */
final readonly class PruneWrittenMessagesHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = OpsPermissions::LOG_PRUNE;

    public const int MONTHS = 12;

    public function __construct(
        private Authorizer $authorizer,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return array{staff_messages: int, notices: int, unconfirmed_guests: int} how many went from each
     *
     * @throws Unauthorized
     */
    public function handle(PruneWrittenMessages $command): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $now = CarbonImmutable::now();
        $year = $now->subMonthsNoOverflow(self::MONTHS)->utc()->format('Y-m-d H:i:s.uP');
        $week = $now->subDays(NewsletterSignUps::DAYS)->utc()->format('Y-m-d H:i:s.uP');

        return $this->db->transaction(fn (): array => [
            'staff_messages' => $this->db->delete("DELETE FROM ops.staff_messages WHERE created_at < ?::timestamptz AND status IN ('SENT', 'FAILED')", [$year]),
            'notices' => $this->db->delete("DELETE FROM ops.notices WHERE created_at < ?::timestamptz AND status IN ('SENT', 'CANCELLED', 'FAILED')", [$year]),
            'unconfirmed_guests' => $this->db->delete("DELETE FROM ops.subscribers WHERE confirmed_at IS NULL AND kind = 'GUEST' AND created_at < ?::timestamptz", [$week]),
        ]);
    }
}

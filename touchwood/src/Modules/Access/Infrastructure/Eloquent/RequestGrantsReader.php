<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Authorization\StaffGrants;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * Each staff member's permissions, read once per web request (amendment 65; owner, 2026-10-08).
 *
 * The admin panel asks the same person's permissions once per menu entry, per Home card and per
 * page: 26 times on a Catalog list page, each a read of the cache table (2 queries), before the
 * page read anything of its own. Bound **scoped**, so what it remembers lasts one request; a
 * web server process serves one request, and nothing here outlives it.
 *
 * - **Only in a web request.** The system - a console command, a queued job - is never answered
 *   from memory: it reads the cache every time, exactly as before. A web request never acts as the
 *   system (CONVENTIONS.md, "Who acts"), so this is the same as "only in a web request".
 * - **A change forgets.** `refresh()` - called inside the transaction of every change to someone's
 *   permissions - forgets that person and reads them from the cache for the rest of the request,
 *   so a change made in the request, committed or rolled back, is never answered from memory.
 * - **Not inside a transaction opened after the request began.** A handler checks the author again
 *   after taking its locks (ChangeStaffRole, for one), and a revocation it waited on must stop it:
 *   there every read goes to the cache, as before, and nothing read there is kept. The frame and a
 *   page's own reads run outside any transaction, so they are answered from memory.
 * - **What it cannot see** outside a transaction: a change another process commits while this
 *   request runs. The request shows the permissions as they were when it first asked; the next
 *   request sees the change.
 */
final class RequestGrantsReader implements GrantsReader
{
    /** @var array<string, StaffGrants|null> staff id => what the cache answered */
    private array $read = [];

    /** @var array<string, true> staff ids changed in this request: never answered from memory again */
    private array $changed = [];

    /** The transaction level the request reads at: 0, or the test's own transaction. */
    private readonly int $level;

    public function __construct(
        private readonly CachedGrantsReader $cached,
        private readonly ActorContext $actors,
        private readonly ConnectionInterface $db,
    ) {
        $this->level = $db->transactionLevel();
    }

    public function forStaff(string $staffId): ?StaffGrants
    {
        $key = strtolower($staffId);

        if (isset($this->changed[$key])
            || $this->actors->current()->type === ActorType::System
            || $this->db->transactionLevel() !== $this->level) {
            return $this->cached->forStaff($staffId);
        }

        if (! array_key_exists($key, $this->read)) {
            $this->read[$key] = $this->cached->forStaff($staffId);
        }

        return $this->read[$key];
    }

    public function refresh(string ...$staffIds): void
    {
        $this->cached->refresh(...$staffIds);

        foreach ($staffIds as $staffId) {
            $this->changed[strtolower($staffId)] = true;
        }
    }
}

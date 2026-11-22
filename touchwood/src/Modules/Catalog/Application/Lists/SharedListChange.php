<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * What every change to a shared list shares (catalog.md §1.5–§1.11, §3):
 *
 * 1. **The permission with All stores**, before anything is read: a shared list reaches every store,
 *    so only a Super Admin or someone given the job with All stores changes it (owner, 2026-10-02).
 * 2. **Its own transaction**, retried on a deadlock; the work inside reads again whatever it changes,
 *    since a retried transaction runs it again (lesson 57).
 * 3. **The list's lock first** (`ListLocks`), so every question about the other rows is answered
 *    under it.
 * 4. **The audit entries** the work answers — none when nothing changed — inside the same
 *    transaction (Platform §1.5).
 */
final readonly class SharedListChange
{
    public function __construct(
        private Authorizer $authorizer,
        private ListLocks $locks,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function authorize(string $permission): void
    {
        $this->authorizer->authorize($permission, PermissionScope::allStores());
    }

    /**
     * As run(), for a list change that changes products or their listing rows too — deactivating or
     * activating a category or a brand, renaming or moving a category, a brand's place in default
     * listings: **the products' lock first, then the list's**. A product change holds the products'
     * lock before it row-locks a category or brand, so the two never wait on each other in a circle.
     *
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work
     * @return T
     */
    public function runAfterProducts(string $list, Closure $work): mixed
    {
        return $this->db->transaction(function () use ($list, $work): mixed {
            $this->locks->lock(ListLocks::PRODUCTS);
            $this->locks->lock($list);
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }

    /**
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work  answers its result and its audit entries
     * @return T
     */
    public function run(string $list, Closure $work): mixed
    {
        return $this->db->transaction(function () use ($list, $work): mixed {
            $this->locks->lock($list);
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }
}

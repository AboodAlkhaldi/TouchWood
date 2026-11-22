<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeactivateStore;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\Audit\StoreAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\BaseStoreAlwaysActive;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Modules\Platform\Public\Events\StoreDeactivated;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Turns a store off (platform.md §1.1, §1.6, §3; owner, 2026-10-01). Super Admin only, through the
 * reserved `platform.store.switch`. The base store is always on (`BaseStoreAlwaysActive`, owner
 * 2026-10-02), refused by the store itself before the database's CHECK would refuse it.
 *
 * Nothing is deleted or rewritten: every read that offers a store filters on the switch, and work
 * already under way in it — its open orders, jobs dispatched in it — goes on. A store already off is
 * left alone: nothing is written, audited or announced.
 */
final readonly class DeactivateStoreHandler
{
    public const string PERMISSION = PlatformPermissions::STORE_SWITCH;

    public function __construct(
        private Authorizer $authorizer,
        private StoreRepository $stores,
        private ConnectionInterface $db,
        private Dispatcher $events,
        private StoreDirectory $directory,
        private AuditLog $auditLog,
    ) {}

    /**
     * @throws BaseStoreAlwaysActive|StoreNotFound
     */
    public function handle(DeactivateStore $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = StoreCode::fromString($command->storeCode);

        // Self-contained, so a retried attempt reads the row again under its lock.
        $this->db->transaction(function () use ($code): void {
            $store = $this->stores->byCode($code) ?? throw new StoreNotFound($code->value);
            $store->deactivate();

            if ($store->pullChanges() === []) {
                return;
            }

            $this->stores->update($store);
            $this->auditLog->record(StoreAudit::switched($store));
            $this->directory->invalidate();
            $this->events->dispatch(new StoreDeactivated((string) Str::uuid(), $store->id()->value, CarbonImmutable::now()));
        }, 3);
    }
}

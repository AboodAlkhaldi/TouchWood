<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\ActivateStore;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\Audit\StoreAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Modules\Platform\Public\Events\StoreActivated;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Turns a store on (platform.md §1.1, §3; owner, 2026-10-01). Super Admin only, through the reserved
 * `platform.store.switch`, checked store-free: the switch decides whether a store is a place to work
 * in at all.
 *
 * A store already on is left alone: nothing is written, audited or announced.
 */
final readonly class ActivateStoreHandler
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

    public function handle(ActivateStore $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = StoreCode::fromString($command->storeCode);

        // Self-contained, so a retried attempt reads the row again under its lock.
        $this->db->transaction(function () use ($code): void {
            $store = $this->stores->byCode($code) ?? throw new StoreNotFound($code->value);
            $store->activate();

            if ($store->pullChanges() === []) {
                return;
            }

            $this->stores->update($store);
            $this->auditLog->record(StoreAudit::switched($store));
            $this->directory->invalidate();
            $this->events->dispatch(new StoreActivated((string) Str::uuid(), $store->id()->value, CarbonImmutable::now()));
        }, 3);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Who a daily report is for, and what they may see (ops.md §1.11, §3: `ops.report.daily`, the stores
 * they may view; every store's report a super admin's alone).
 *
 * The person is the staff member asking - or, inside the queued email, the one who switched it on: the
 * job runs as the system on their behalf, and `storesWith()` answers for them (CONVENTIONS, "scope still
 * follows the requester"). So each handler authorizes, then asks here whether the person holds the
 * permission in the store: in a request the two agree; in the job only the second speaks for the person.
 */
final readonly class DailyReportPeople
{
    public const string PERMISSION = OpsPermissions::REPORT_DAILY;

    public function __construct(
        private ActorContext $actors,
        private Authorizer $authorizer,
        private AccessApi $access,
        private PlatformApi $platform,
    ) {}

    /**
     * The staff member, active.
     *
     * @throws Unauthorized
     */
    public function person(): StaffDto
    {
        $actor = $this->actors->current();
        $person = match (true) {
            $actor->type === ActorType::Staff => $actor,
            $actor->type === ActorType::System && $actor->requestedBy?->type === ActorType::Staff => $actor->requestedBy,
            default => null,
        };
        $staff = $person?->id === null ? null : $this->access->staff($person->id);

        return $staff !== null && $staff->status === StaffStatus::Active ? $staff : throw new Unauthorized(self::PERMISSION);
    }

    /** A staff member by id, whatever their state; null for none. */
    public function staff(string $id): ?StaffDto
    {
        return $this->access->staff($id);
    }

    /**
     * The stores whose reports the person may make and open; null for every store's.
     *
     * @return list<StoreId>|null
     */
    public function stores(): ?array
    {
        return $this->authorizer->storesWith(self::PERMISSION);
    }

    /** @throws Unauthorized */
    public function requireStore(StoreId $store): void
    {
        $stores = $this->stores();
        if ($stores !== null && ! in_array($store->value, array_map(static fn (StoreId $id): string => $id->value, $stores), true)) {
            throw new Unauthorized(self::PERMISSION);
        }
    }

    /** @throws Unauthorized */
    public function requireAllStores(StaffDto $person): void
    {
        if (! $person->isSuperAdmin || $this->stores() !== null) {
            throw new Unauthorized(self::PERMISSION);
        }
    }

    /**
     * A store a request names: on or off (a super admin may view an off one), never one that does not exist.
     *
     * @throws InvalidOpsInput
     */
    public function store(string $id): StoreDto
    {
        $id = strtolower(trim($id));
        $store = Ulids::valid($id) ? $this->platform->store(StoreId::fromString($id)) : null;

        return $store ?? throw new InvalidOpsInput('store', 'not a store');
    }

    /**
     * The stores of every store's report: **on and off**, in their order - an off store marked as such,
     * its figures in the totals (owner, 2026-10-30).
     *
     * @return non-empty-list<StoreDto>
     */
    public function everyStore(): array
    {
        $stores = $this->platform->allStores();

        return $stores !== [] ? $stores : throw new InvalidOpsInput('store', 'no store');
    }

    /** The time zone every store's report is read in: the base store's (ops.md §1.11, owner 2026-10-28). */
    public function allStoresZone(): string
    {
        foreach ($this->platform->allStores() as $store) {
            if ($store->isBase) {
                return $store->timezone;
            }
        }

        throw new InvalidOpsInput('store', 'no base store');
    }

    /** The zone a report's or an email's time is read in. */
    public function zone(?StoreDto $store): string
    {
        return $store === null ? $this->allStoresZone() : $store->timezone;
    }
}

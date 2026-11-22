<?php

declare(strict_types=1);

namespace Modules\Feedback\Application;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Modules\Feedback\Domain\Exception\FeedbackError;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every staff action on reviews and questions shares (feedback.md §1.6, §3, §7), as Promotions'
 * `MarketingChange`:
 *
 * 1. **The item's store, which the staff member must cover** — one they do not is answered as an
 *    unknown item; someone holding the job nowhere is simply not allowed.
 * 2. **The permission in that store**, by a staff member — who is recorded as the moderator.
 * 3. **A store switched off** reached by whoever may switch stores alone — Super Admins (§1.6).
 * 4. **Its own transaction**, the audit entries the work answers inside it.
 */
final readonly class StaffChange
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * The item's store, authorized, and the staff member acting.
     *
     * @param  Closure(): ?StoreId  $storeOf
     * @param  Closure(): FeedbackError  $notFound
     * @return array{StoreId, string}
     *
     * @throws Unauthorized|FeedbackError
     */
    public function authorize(string $permission, Closure $storeOf, Closure $notFound): array
    {
        $covered = $this->authorizer->storesWith($permission);

        if ($covered === []) {
            throw new Unauthorized($permission);
        }

        $store = $storeOf() ?? throw $notFound();

        if ($covered !== null && ! in_array($store->value, array_map(static fn (StoreId $id): string => $id->value, $covered), true)) {
            throw $notFound();
        }

        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        $this->refuseOffStore($permission, $store);

        return [$store, strtolower($actor->id)];
    }

    /**
     * An off store's rows are changed only by those who may switch stores on and off (a Super Admin).
     *
     * @throws Unauthorized
     */
    public function refuseOffStore(string $permission, StoreId $store): void
    {
        $found = $this->platform->store($store);

        if ($found !== null && ! $found->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new Unauthorized($permission);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        return $this->db->transaction(function () use ($work): mixed {
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }
}

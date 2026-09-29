<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Types;

use Closure;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every staff change to a store's two lists shares (b2b.md §1.3, §3.2, amendment 10).
 *
 * - **The store is the list's own**: a type's permission is checked in the type's store; adding one,
 *   or marking the lists reviewed, in the store named. Someone who holds the job in no store is
 *   refused by its name before anything is read; a type of a store they do not cover answers
 *   exactly as one that does not exist, `TypeNotFound` (10(k)); a store they do not cover, named by
 *   them, is refused as not allowed.
 * - **One order for every change** (README): in its own transaction, the store's type-list lock
 *   first — before any account's —, then the change; **anything changed clears the store's "copied
 *   from the starting lists" notice** (10(d)); then the audit entries, inside the same transaction.
 */
final readonly class StaffTypeAction
{
    public function __construct(
        private Authorizer $authorizer,
        private CompanyTypeRepository $companyTypes,
        private DocumentTypeRepository $documentTypes,
        private StoreTypeListsRepository $lists,
        private TypeListsNotice $notice,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * A store named by the staff member — to add a type to, or to mark reviewed.
     *
     * @return array{PermissionScope, string} the scope to check in, and the store's id
     *
     * @throws InvalidCompanyAttribute|Unauthorized
     */
    public function store(string $permission, string $storeId): array
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === []) {
            throw new Unauthorized($permission);
        }

        try {
            $store = StoreId::fromString(trim($storeId));
        } catch (InvalidArgumentException) {
            throw new InvalidCompanyAttribute('store', 'a store');
        }

        if ($stores !== null && ! self::covers($stores, $store)) {
            throw new Unauthorized($permission);
        }

        // Only now, and only for someone who may use it, is the store looked up.
        if ($this->platform->store($store) === null) {
            throw new InvalidCompanyAttribute('store', 'a store');
        }

        return [PermissionScope::store($store), $store->value];
    }

    /**
     * @return array{PermissionScope, CompanyType} the type's store to check in, and the type read now
     *
     * @throws TypeNotFound|Unauthorized
     */
    public function companyType(string $permission, string $typeId): array
    {
        $this->requireSomewhere($permission);
        $type = $this->companyTypes->find($typeId) ?? throw new TypeNotFound($typeId);

        return [$this->scopeOf($permission, $type->storeId(), $typeId), $type];
    }

    /**
     * @return array{PermissionScope, DocumentType} the type's store to check in, and the type read now
     *
     * @throws TypeNotFound|Unauthorized
     */
    public function documentType(string $permission, string $typeId): array
    {
        $this->requireSomewhere($permission);
        $type = $this->documentTypes->find($typeId) ?? throw new TypeNotFound($typeId);

        return [$this->scopeOf($permission, $type->storeId(), $typeId), $type];
    }

    /**
     * Runs one change to a store's lists: its own transaction, the store's lock first, then the work,
     * which answers the audit entries for what it changed — none when nothing did.
     *
     * @param  Closure(): list<AuditEntryDto>  $work  self-contained: it reads again what it changes,
     *                                                since a retried transaction runs it again
     */
    public function change(string $storeId, Closure $work): void
    {
        $this->db->transaction(function () use ($storeId, $work): void {
            $this->lists->lockLists($storeId);
            $entries = $work();

            if ($entries === []) {
                return;
            }

            $this->notice->clear($storeId);

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }
        }, 3);
    }

    /**
     * The store's admins looked at both lists and found nothing to change: the notice goes, and that
     * is audited — only when it was showing.
     */
    public function markReviewed(string $storeId): void
    {
        $this->db->transaction(function () use ($storeId): void {
            $this->lists->lockLists($storeId);

            if ($this->notice->clear($storeId)) {
                $this->platform->recordAudit(TypeAudit::listsReviewed($storeId));
            }
        }, 3);
    }

    /**
     * @throws Unauthorized
     */
    private function requireSomewhere(string $permission): void
    {
        if ($this->authorizer->storesWith($permission) === []) {
            throw new Unauthorized($permission);
        }
    }

    /**
     * @throws TypeNotFound
     */
    private function scopeOf(string $permission, string $storeId, string $typeId): PermissionScope
    {
        $stores = $this->authorizer->storesWith($permission);
        $store = StoreId::fromString($storeId);

        if ($stores !== null && ! self::covers($stores, $store)) {
            throw new TypeNotFound($typeId);
        }

        return PermissionScope::store($store);
    }

    /**
     * @param  list<StoreId>  $stores
     */
    private static function covers(array $stores, StoreId $store): bool
    {
        foreach ($stores as $each) {
            if ($each->equals($store)) {
                return true;
            }
        }

        return false;
    }
}

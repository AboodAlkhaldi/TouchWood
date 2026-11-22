<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Staff;

use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every staff action on one company shares (b2b.md §3.2): the store its permission is checked
 * in is **the account's home store**, on the company's own row.
 *
 * A staff member who holds the job somewhere, but not in this company's store, is told exactly what
 * they would be told for an id that never existed — `CompanyNotFound` — so the panel never confirms
 * which companies are real (§7). Someone who holds the job in no store at all is refused plainly, by
 * its name, before anything is read (as Access's StaffCustomerAction does).
 *
 * Each handler still checks its own permission with the scope this hands back, and does its work in
 * its own transaction, reading the company again under the account's lock.
 */
final readonly class StaffCompanyAction
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private CompanyRepository $companies,
    ) {}

    /**
     * @return array{PermissionScope, Company} the home store to check in, and the company as read
     *                                         now, unlocked — for its account id and home store,
     *                                         which never change
     *
     * @throws CompanyNotFound|Unauthorized
     */
    public function about(string $permission, string $companyId): array
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === []) {
            throw new Unauthorized($permission);
        }

        $company = $this->companies->find($companyId) ?? throw new CompanyNotFound;
        $home = StoreId::fromString($company->homeStoreId());

        // null is what storesWith() answers for every store, now and for one opened later.
        if ($stores !== null && ! self::covers($stores, $home)) {
            throw new CompanyNotFound;
        }

        return [PermissionScope::store($home), $company];
    }

    /**
     * The staff member deciding: a decision is recorded against a staff member (`decided_by`,
     * `status_changed_by`), so only one may make it — not the system, which the permission check
     * lets through.
     *
     * @throws Unauthorized
     */
    public function decider(string $permission): string
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        return $actor->id;
    }

    /**
     * @param  list<StoreId>  $stores
     */
    private static function covers(array $stores, StoreId $home): bool
    {
        foreach ($stores as $store) {
            if ($store->equals($home)) {
                return true;
            }
        }

        return false;
    }
}

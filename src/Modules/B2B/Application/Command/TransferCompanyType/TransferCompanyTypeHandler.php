<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\TransferCompanyType;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Staff\CompanyTypeHolders;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`TransferCompanyType`** (b2b.md §1.3, §3.2, amendment 11(c)): every company holding one active
 * type moves to another active type of the same store — a clear transfer between two types that both
 * stay offered. Each move is a staff correction (`CompanyTypeHolders`): the company changes, never an
 * application it sent, nobody goes back to `PENDING`, each company is audited, an open draft follows
 * while it still held the company's type, and **a suspended company is skipped** (10(h)).
 *
 * Its own job, `b2b.company.transfer_type`, checked in the types' store. It changes neither list, so
 * it leaves the store's "copied" notice as it is. One transaction, in B2B's one lock order: the
 * store's type-list lock — so no deactivation of either type runs meanwhile —, then each account in
 * account order, then its company. A type of a store the staff member does not cover answers as one
 * that does not exist; a target unknown, of another store, or the same type is `InvalidCompanyAttribute`;
 * either type inactive is `CompanyTypeInactive` — to move companies off a deactivated type, deactivate
 * it with a replacement.
 */
final readonly class TransferCompanyTypeHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_TRANSFER_TYPE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private CompanyTypeHolders $holders,
        private CompanyTypeRepository $types,
        private StoreTypeListsRepository $lists,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return int how many companies moved
     *
     * @throws CompanyTypeInactive|InvalidCompanyAttribute|TypeNotFound|Unauthorized
     */
    public function handle(TransferCompanyType $command): int
    {
        [$scope, $found] = $this->action->companyType(self::PERMISSION, $command->fromTypeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);

        return $this->db->transaction(function () use ($found, $command, $scope): int {
            $this->lists->lockLists($found->storeId());

            // Read again under the store's lock, not row-locked (see DeactivateCompanyTypeHandler).
            $from = $this->types->find($found->id()) ?? throw new TypeNotFound($found->id());

            if (! $from->isActive()) {
                throw new CompanyTypeInactive;
            }

            $to = $this->holders->target($from, $command->toTypeId, 'target');
            $moved = $this->holders->move($from, $to, $scope, 'b2b.company.type_transferred');
            $this->platform->recordAudit(TypeAudit::transferred($from, $to, $moved));

            return $moved;
        }, 3);
    }
}

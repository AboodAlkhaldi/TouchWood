<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DeactivateCompanyType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Staff\CompanyTypeHolders;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNameTaken;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\TypeName;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`DeactivateCompanyType`** (b2b.md §1.3, §3.2, amendments 5 and 11): no new application may choose
 * it, and it shows to one hidden or greyed out. Deactivating one already inactive changes how it
 * shows — and, with a replacement, still moves the companies holding it.
 *
 * **The staff member deactivating it decides for its holders, once** (amendment 11): leave them, or
 * move every one of them — approved ones included, suspended ones not (10(h)) — to another active type
 * of the same store, or to **a new type created in the same step**, which needs
 * `b2b.company_type.create` as well. The reviewer of a waiting application then follows that decision
 * (11(a)). The old type may be activated again later; the companies moved off it stay where they are.
 *
 * All in one transaction — the new type, the deactivation and every move happen together or not at
 * all — in B2B's one lock order: the store's lists, then each account in turn, in account order, then
 * its company. **The type's own row is read, not locked**: the store's lock already serialises every
 * writer of the list, and a `FOR UPDATE` on it would block the foreign-key check of a company saving a
 * draft that points at it — while this waits for that company's account lock (the review of step 4).
 */
final readonly class DeactivateCompanyTypeHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_TYPE_DEACTIVATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private CompanyTypeHolders $holders,
        private CompanyTypeRepository $types,
    ) {}

    /**
     * @return string|null the new type's id, when one was created to replace it
     *
     * @throws CompanyTypeInactive|InvalidCompanyAttribute|TypeNameTaken|TypeNotFound|Unauthorized
     */
    public function handle(DeactivateCompanyType $command): ?string
    {
        [$scope, $found] = $this->action->companyType(self::PERMISSION, $command->typeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $newName = null;

        if ($command->newTypeNameAr !== null || $command->newTypeNameEn !== null) {
            if ($command->replacementTypeId !== null) {
                throw new InvalidCompanyAttribute('replacement', 'an existing type or a new one, not both');
            }

            // Adding a type is its own job (amendment 11(b)): asked before anything is written.
            $this->authorizer->authorize(B2BPermissions::COMPANY_TYPE_CREATE, $scope);
            $newName = TypeName::of((string) $command->newTypeNameAr, (string) $command->newTypeNameEn);
        }

        $newId = $newName === null ? null : $this->types->nextId();

        $this->action->change($found->storeId(), function () use ($found, $command, $scope, $newName, $newId): array {
            $type = $this->types->find($found->id()) ?? throw new TypeNotFound($found->id());
            $entries = [];
            $replacement = null;

            if ($newName !== null && $newId !== null) {
                $replacement = $this->add($type, $newId, $newName, $command->newTypePosition);
                $entries[] = TypeAudit::added($replacement);
            } elseif ($command->replacementTypeId !== null) {
                $replacement = $this->holders->target($type, $command->replacementTypeId);
            }

            $wasActive = $type->isActive();
            $wasShown = $type->inactiveDisplay();
            $type->deactivate($command->shown);
            $changed = $type->pullChanges() !== [];

            if ($changed) {
                $this->types->update($type);
            }

            if ($replacement !== null) {
                $this->holders->move($type, $replacement, $scope, 'b2b.company.type_replaced');
            }

            if ($changed || $replacement !== null) {
                $entries[] = TypeAudit::deactivated($type, $wasActive, $wasShown, $replacement?->id());
            }

            return $entries;
        });

        return $newId;
    }

    /**
     * The new type the holders move to: in the same store, active, its names not another type's.
     *
     * @throws InvalidCompanyAttribute|TypeNameTaken
     */
    private function add(CompanyType $replacing, string $id, TypeName $name, ?int $position): CompanyType
    {
        if ($this->types->nameTaken($replacing->storeId(), $name)) {
            throw new TypeNameTaken;
        }

        $type = CompanyType::add($id, $replacing->storeId(), $name, $position ?? $replacing->position());
        $this->types->add($type);

        return $type;
    }
}

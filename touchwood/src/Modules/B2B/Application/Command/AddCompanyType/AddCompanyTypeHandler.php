<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\AddCompanyType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNameTaken;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\TypeName;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`AddCompanyType`** (b2b.md §1.3, §3.2): a new type in a store's list, active, its names unique in
 * that store in either language, ignoring case. Clears the store's "copied" notice (amendment 10(d)).
 */
final readonly class AddCompanyTypeHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_TYPE_CREATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private CompanyTypeRepository $types,
    ) {}

    /**
     * @return string the new type's id
     *
     * @throws InvalidCompanyAttribute|TypeNameTaken|Unauthorized
     */
    public function handle(AddCompanyType $command): string
    {
        [$scope, $storeId] = $this->action->store(self::PERMISSION, $command->storeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $name = TypeName::of($command->nameAr, $command->nameEn);
        $id = $this->types->nextId();

        $this->action->change($storeId, function () use ($id, $storeId, $name, $command): array {
            if ($this->types->nameTaken($storeId, $name)) {
                throw new TypeNameTaken;
            }

            $type = CompanyType::add($id, $storeId, $name, $command->position);
            $this->types->add($type);

            return [TypeAudit::added($type)];
        });

        return $id;
    }
}

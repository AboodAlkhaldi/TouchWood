<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\MoveCompanyType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`MoveCompanyType`** (b2b.md §1.3, §3.2): its position on the form; two may share one, and the
 * English name then decides.
 */
final readonly class MoveCompanyTypeHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_TYPE_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private CompanyTypeRepository $types,
    ) {}

    /**
     * @throws InvalidCompanyAttribute|TypeNotFound|Unauthorized
     */
    public function handle(MoveCompanyType $command): void
    {
        [$scope, $found] = $this->action->companyType(self::PERMISSION, $command->typeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);

        $this->action->change($found->storeId(), function () use ($found, $command): array {
            $type = $this->types->find($found->id()) ?? throw new TypeNotFound($found->id());
            $from = $type->position();
            $type->moveTo($command->position);

            if ($type->pullChanges() === []) {
                return [];
            }

            $this->types->update($type);

            return [TypeAudit::moved($type, $from)];
        });
    }
}

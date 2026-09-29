<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ActivateCompanyType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`ActivateCompanyType`** (b2b.md §3.2, amendment 10(c)): a deactivated type is offered again — a
 * type is never deleted, so one deactivated by mistake comes back this way. The companies moved off
 * it when it was replaced stay where they are.
 */
final readonly class ActivateCompanyTypeHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_TYPE_DEACTIVATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private CompanyTypeRepository $types,
    ) {}

    /**
     * @throws TypeNotFound|Unauthorized
     */
    public function handle(ActivateCompanyType $command): void
    {
        [$scope, $found] = $this->action->companyType(self::PERMISSION, $command->typeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);

        $this->action->change($found->storeId(), function () use ($found): array {
            $type = $this->types->byId($found->id()) ?? throw new TypeNotFound($found->id());
            $wasShown = $type->inactiveDisplay();
            $type->activate();

            if ($type->pullChanges() === [] || $wasShown === null) {
                return [];
            }

            $this->types->update($type);

            return [TypeAudit::activated($type, $wasShown)];
        });
    }
}

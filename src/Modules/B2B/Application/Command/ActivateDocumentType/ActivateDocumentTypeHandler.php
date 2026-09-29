<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ActivateDocumentType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`ActivateDocumentType`** (b2b.md §3.2, amendment 10(c)): a deactivated paper is asked for again —
 * a type is never deleted, so one deactivated by mistake comes back this way.
 */
final readonly class ActivateDocumentTypeHandler
{
    public const string PERMISSION = B2BPermissions::DOCUMENT_TYPE_DEACTIVATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private DocumentTypeRepository $types,
    ) {}

    /**
     * @throws TypeNotFound|Unauthorized
     */
    public function handle(ActivateDocumentType $command): void
    {
        [$scope, $found] = $this->action->documentType(self::PERMISSION, $command->typeId);
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

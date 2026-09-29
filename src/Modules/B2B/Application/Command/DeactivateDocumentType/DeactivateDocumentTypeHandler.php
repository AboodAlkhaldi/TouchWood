<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DeactivateDocumentType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`DeactivateDocumentType`** (b2b.md §1.3, §3.2, amendment 5): nothing new is uploaded under it, and
 * it shows to new applications hidden or greyed out. A draft holding a file under it has that file
 * marked "no longer accepted" until it is removed; what was sent is untouched.
 */
final readonly class DeactivateDocumentTypeHandler
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
    public function handle(DeactivateDocumentType $command): void
    {
        [$scope, $found] = $this->action->documentType(self::PERMISSION, $command->typeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);

        $this->action->change($found->storeId(), function () use ($found, $command): array {
            $type = $this->types->find($found->id()) ?? throw new TypeNotFound($found->id());
            $wasActive = $type->isActive();
            $wasShown = $type->inactiveDisplay();
            $type->deactivate($command->shown);

            if ($type->pullChanges() === []) {
                return [];
            }

            $this->types->update($type);

            return [TypeAudit::deactivated($type, $wasActive, $wasShown)];
        });
    }
}

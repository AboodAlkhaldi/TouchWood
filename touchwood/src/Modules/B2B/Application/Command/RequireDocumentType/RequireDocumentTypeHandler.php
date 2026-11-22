<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RequireDocumentType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`RequireDocumentType`** (b2b.md §1.3, §3.2): required, or optional again. **Never retroactive**:
 * it changes what the next application must send, and nothing about a company already approved.
 */
final readonly class RequireDocumentTypeHandler
{
    public const string PERMISSION = B2BPermissions::DOCUMENT_TYPE_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private DocumentTypeRepository $types,
    ) {}

    /**
     * @throws TypeNotFound|Unauthorized
     */
    public function handle(RequireDocumentType $command): void
    {
        [$scope, $found] = $this->action->documentType(self::PERMISSION, $command->typeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);

        $this->action->change($found->storeId(), function () use ($found, $command): array {
            $type = $this->types->find($found->id()) ?? throw new TypeNotFound($found->id());
            $command->required ? $type->require() : $type->makeOptional();

            if ($type->pullChanges() === []) {
                return [];
            }

            $this->types->update($type);

            return [TypeAudit::requirement($type)];
        });
    }
}

<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\MoveDocumentType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`MoveDocumentType`** (b2b.md §1.3, §3.2): its position on the form.
 */
final readonly class MoveDocumentTypeHandler
{
    public const string PERMISSION = B2BPermissions::DOCUMENT_TYPE_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private DocumentTypeRepository $types,
    ) {}

    /**
     * @throws InvalidCompanyAttribute|TypeNotFound|Unauthorized
     */
    public function handle(MoveDocumentType $command): void
    {
        [$scope, $found] = $this->action->documentType(self::PERMISSION, $command->typeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);

        $this->action->change($found->storeId(), function () use ($found, $command): array {
            $type = $this->types->byId($found->id()) ?? throw new TypeNotFound($found->id());
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

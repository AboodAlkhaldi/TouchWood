<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RenameDocumentType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNameTaken;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\ValueObject\TypeName;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`RenameDocumentType`** (b2b.md §3.2): new names, still unique in the store in either language,
 * ignoring case.
 */
final readonly class RenameDocumentTypeHandler
{
    public const string PERMISSION = B2BPermissions::DOCUMENT_TYPE_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private DocumentTypeRepository $types,
    ) {}

    /**
     * @throws InvalidCompanyAttribute|TypeNameTaken|TypeNotFound|Unauthorized
     */
    public function handle(RenameDocumentType $command): void
    {
        [$scope, $found] = $this->action->documentType(self::PERMISSION, $command->typeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $name = TypeName::of($command->nameAr, $command->nameEn);

        $this->action->change($found->storeId(), function () use ($found, $name): array {
            $type = $this->types->byId($found->id()) ?? throw new TypeNotFound($found->id());

            if ($this->types->nameTaken($type->storeId(), $name, $type->id())) {
                throw new TypeNameTaken;
            }

            $from = $type->name();
            $type->rename($name);

            if ($type->pullChanges() === []) {
                return [];
            }

            $this->types->update($type);

            return [TypeAudit::renamed($type, $from)];
        });
    }
}

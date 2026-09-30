<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\AttachApplicationDocument;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Draft\OpenDrafts;
use Modules\B2B\Application\Files\ApplicationFiles;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\DocumentTypeInactive;
use Modules\B2B\Domain\Exception\DuplicateDocumentFile;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\MediaFilename;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\Error\DomainError;

/**
 * Uploads a paper into the open draft (b2b.md §1.4, §3.1). B2B uploads it itself, as a private
 * file under the company's own permission (amendment 5).
 *
 * **Nothing new is uploaded under a type that is not offered** (amendment 4): an inactive type, one
 * of another store, or one that does not exist is refused with DocumentTypeInactive **before the
 * file is stored**. A file the new upload replaces is let go of — deleted unless another
 * application, such as the last one sent, still holds it.
 *
 * **Nor a file named exactly as one under another document type of the draft** (DuplicateDocumentFile,
 * amendment 16(c)), also before anything is stored — both names as the media library keeps them
 * (17(d)). The type's own file is not compared: uploading again under the same type replaces it.
 *
 * Not audited (amendment 4); Platform keeps its own entry for the upload.
 */
final readonly class AttachApplicationDocumentHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private OpenDrafts $drafts,
        private ApplicationRepository $applications,
        private DocumentTypeRepository $documentTypes,
        private ApplicationFiles $files,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NotACompanyAccount|DocumentTypeInactive|DuplicateDocumentFile
     * @throws ApplicationNotFound|CompanySuspended|ApplicationNotEditable
     * @throws DomainError Platform's refusal of the file itself (type or size)
     */
    public function handle(AttachApplicationDocument $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $account = $this->account->get(self::PERMISSION);
        $typeId = strtolower($command->documentTypeId);

        $this->db->transaction(function () use ($account, $typeId, $command): void {
            $draft = $this->drafts->forChange($account->id)->draft;

            $type = $this->documentTypes->find($typeId);

            if ($type === null || strtolower($type->storeId()) !== strtolower($account->homeStoreId) || ! $type->isActive()) {
                throw new DocumentTypeInactive($typeId);
            }

            // Compared as the library keeps names (amendment 17(d)): a space at the ends or an
            // invisible mark does not make a second name.
            $name = MediaFilename::kept($command->originalFilename);

            foreach ($draft->documents() as $document) {
                if ($document->documentTypeId !== $typeId
                    && $this->platform->media($document->mediaId)?->originalFilename === $name) {
                    throw new DuplicateDocumentFile;
                }
            }

            $mediaId = $this->files->upload($command->path, $command->originalFilename);
            $replaced = $draft->attach($typeId, $mediaId, CarbonImmutable::now());

            $this->applications->update($draft);
            $this->files->release([$replaced]);
        }, 3);
    }
}

<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RemoveApplicationDocument;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Draft\OpenDrafts;
use Modules\B2B\Application\Files\ApplicationFiles;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * **Nothing blocks the company removing a file from its own draft** (b2b.md §1.4, amendment 5) —
 * except a suspension, which freezes the draft (amendment 9(a)). A file only the draft holds is
 * deleted; one carried from the last application sent stays with that application and simply
 * leaves the draft. Removing a type that holds nothing changes nothing.
 *
 * Not audited (amendment 4).
 */
final readonly class RemoveApplicationDocumentHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private OpenDrafts $drafts,
        private ApplicationRepository $applications,
        private ApplicationFiles $files,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NotACompanyAccount|ApplicationNotFound|CompanySuspended|ApplicationNotEditable
     */
    public function handle(RemoveApplicationDocument $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $account = $this->account->get(self::PERMISSION);
        $customerId = $account->id;
        $store = $this->account->store($account);
        $typeId = strtolower($command->documentTypeId);

        $this->db->transaction(function () use ($customerId, $store, $typeId): void {
            $draft = $this->drafts->forChange($customerId, $store)->draft;
            $removed = $draft->detach($typeId);

            if ($removed === null) {
                return;
            }

            $this->applications->update($draft);
            $this->files->release([$removed]);
        }, 3);
    }
}

<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RemoveApplicationAnswer;

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
 * The mirror of removing a paper (b2b.md §1.2, amendment 5): nothing blocks it but a suspension
 * (amendment 9(a)). A file answer only the draft holds is deleted. Removing an answer that is not
 * there changes nothing. Not audited (amendment 4).
 */
final readonly class RemoveApplicationAnswerHandler
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
    public function handle(RemoveApplicationAnswer $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->account->get(self::PERMISSION)->id;

        $this->db->transaction(function () use ($customerId, $command): void {
            $draft = $this->drafts->forChange($customerId)->draft;
            $before = $draft->answers();
            $removed = $draft->removeAnswer($command->requestId);

            // Nothing was there: nothing to write.
            if (count($draft->answers()) === count($before)) {
                return;
            }

            $this->applications->update($draft);
            $this->files->release([$removed]);
        }, 3);
    }
}

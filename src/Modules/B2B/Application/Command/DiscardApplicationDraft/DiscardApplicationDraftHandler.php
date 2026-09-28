<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DiscardApplicationDraft;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\Audit\CompanyAccountAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Files\ApplicationFiles;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A draft is kept until it is sent, and the customer may throw it away (b2b.md §1.2, amendment 3),
 * **even while the company is suspended** (amendment 5): it is the one thing a suspended company's
 * draft allows. Nothing sent is ever discarded (ApplicationNotEditable).
 *
 * It takes the files **only it holds** with it: one carried from the last application sent stays
 * with that application. The company is untouched — an approved company whose new-details draft is
 * discarded stays approved and keeps ordering. Audited on the application (amendment 4).
 */
final readonly class DiscardApplicationDraftHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private ApplicationRepository $applications,
        private CompanyRepository $companies,
        private ApplicationFiles $files,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NotACompanyAccount|ApplicationNotFound|ApplicationNotEditable
     */
    public function handle(DiscardApplicationDraft $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $account = $this->account->get(self::PERMISSION);

        $this->db->transaction(function () use ($account): void {
            // The same lock order as every draft action (OpenDrafts), without its suspension rule.
            $this->applications->lockAccount($account->id);
            $company = $this->companies->forCustomerLocked($account->id);
            $draft = $this->applications->openFor($account->id) ?? throw new ApplicationNotFound;
            $draft->ensureDiscardable();

            $held = [];

            foreach ($draft->documents() as $document) {
                $held[] = $document->mediaId;
            }

            foreach ($draft->answers() as $answer) {
                $held[] = $answer->mediaId;
            }

            // Its references go first — Platform deletes a file only once nothing holds it.
            $this->applications->delete($draft->id());
            $this->files->release($held);
            $this->platform->recordAudit(CompanyAccountAudit::discarded($draft, $company?->homeStoreId() ?? $account->homeStoreId));
        }, 3);
    }
}

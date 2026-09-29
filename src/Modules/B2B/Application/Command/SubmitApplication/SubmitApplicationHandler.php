<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\SubmitApplication;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\Audit\CompanyAccountAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Draft\OpenDrafts;
use Modules\B2B\Application\Events\CompanyEvents;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\DocumentNoLongerAccepted;
use Modules\B2B\Domain\Exception\EmailNotVerified;
use Modules\B2B\Domain\Exception\FlaggedItemNotReplaced;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\MissingRequiredDocument;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Exception\RequestNotAnswered;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Sends the open draft (b2b.md §1.2, §3.1, §4.1). The company comes to exist here, `PENDING`, with
 * the account's first; a later one — after a rejection, or new details from an approved company —
 * sends the company back to `PENDING`, and it cannot order until staff decide (handoff §8.2).
 *
 * - **A confirmed email is required** (EmailNotVerified); nothing else about the account is — the
 *   phone belongs to ordering, not to applying (§1.2).
 * - Everything the draft must hold is the application's own rule (Application::submit): every
 *   value, the home store's types, nothing deactivated, every required paper, every flag replaced,
 *   every request answered. It is given the home store's whole lists, inactive types included, so
 *   it can tell "no longer accepted" from "unknown".
 * - A suspended company sends nothing (CompanySuspended).
 *
 * One company per account: the company is created only when none exists, under the account's lock
 * (ApplicationRepository::lockAccount), so two first sends cannot both create one. Audited on the
 * application, in the same transaction (amendment 4).
 */
final readonly class SubmitApplicationHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private OpenDrafts $drafts,
        private ApplicationRepository $applications,
        private CompanyRepository $companies,
        private CompanyTypeRepository $companyTypes,
        private DocumentTypeRepository $documentTypes,
        private CompanyEvents $events,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NotACompanyAccount|EmailNotVerified
     * @throws ApplicationNotFound|CompanySuspended|ApplicationNotEditable
     * @throws InvalidCompanyAttribute|CompanyTypeInactive|DocumentNoLongerAccepted|MissingRequiredDocument
     * @throws FlaggedItemNotReplaced|RequestNotAnswered
     */
    public function handle(SubmitApplication $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $account = $this->account->get(self::PERMISSION);

        if (! $account->emailVerified) {
            throw new EmailNotVerified;
        }

        $this->db->transaction(function () use ($account): void {
            $inHand = $this->drafts->forChange($account->id);
            $draft = $inHand->draft;
            $company = $inHand->company;
            $now = CarbonImmutable::now();

            // The company's store once there is one; the account's until then — the same store,
            // since the company takes it from the account (§1.1).
            $homeStoreId = $company?->homeStoreId() ?? $account->homeStoreId;
            $companyId = $company?->id() ?? $this->companies->nextId();

            $details = $draft->submit(
                $companyId,
                $this->companyTypes->all($homeStoreId),
                $this->documentTypes->all($homeStoreId),
                $company === null ? null : $this->applications->lastSent($company->id()),
                $now,
            );

            $before = $company?->status();

            if ($company === null) {
                $company = Company::fromFirstApplication($companyId, $account->id, $homeStoreId, $details, $now);
                $this->companies->add($company);
            } else {
                $company->applyAgain($details, $now);
                $this->companies->update($company);
            }

            $this->applications->update($draft);
            $this->platform->recordAudit(CompanyAccountAudit::submitted($draft, $before, $company->status(), $homeStoreId));
            // Sending always changes the status: a PENDING company cannot send again (applyAgain).
            $this->events->submitted($company, $draft);
            $this->events->statusChanged($company, $before);
        }, 3);
    }
}

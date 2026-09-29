<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Query\ApplicationViews;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * **ViewMyCompany always answers** (b2b.md §3.1, amendment 5):
 *
 * - before there is a company, which step the account is on (§4.3);
 * - the open draft with its values, files, flags, requests and answers, and what is no longer
 *   accepted;
 * - the types the form offers — greyed ones marked, hidden ones left out;
 * - once there is a company, its details, status and reason, and its **history** — the applications
 *   it sent, newest first, each with its values, papers and dates, its state, its reason or note, and
 *   its flags and requests. **No staff names.**
 *
 * Everything is the home store's (amendment 5): a company is offered its home store's lists.
 */
final readonly class ViewMyCompanyHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private CompanyTypeRepository $companyTypes,
        private DocumentTypeRepository $documentTypes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NotACompanyAccount
     */
    public function handle(ViewMyCompany $query): MyCompanyView
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $account = $this->account->get(self::PERMISSION);

        // Several reads, of one moment: the account's lock, shared, so no writer — the first send
        // above all, which creates the company — commits between them. Without it the page could
        // say "finish and submit" for an application just sent (the review of step 3b).
        return $this->db->transaction(function () use ($account): MyCompanyView {
            $this->applications->lockAccountForReading($account->id);

            return $this->read($account);
        });
    }

    private function read(CustomerDto $account): MyCompanyView
    {
        $company = $this->companies->forCustomer($account->id);
        $open = $this->applications->openFor($account->id);
        $draft = $open?->state() === ApplicationState::Draft ? $open : null;
        $homeStoreId = $company?->homeStoreId() ?? $account->homeStoreId;

        $companyTypes = [];

        foreach ($this->companyTypes->all($homeStoreId) as $type) {
            $companyTypes[$type->id()] = $type;
        }

        $documentTypes = [];

        foreach ($this->documentTypes->all($homeStoreId) as $type) {
            $documentTypes[$type->id()] = $type;
        }

        $lastSent = $company === null ? null : $this->applications->lastSent($company->id());

        return new MyCompanyView(
            $company === null ? $this->stage($account->emailVerified, $open) : null,
            $company === null ? null : new CompanyView(
                $company->id(),
                ApplicationViews::values(
                    $company->details()->name->value, $company->details()->type, $company->details()->crNumber->value,
                    $company->details()->taxNumber->value, $company->details()->address->value, null, $companyTypes,
                ),
                $company->status()->value,
                $company->statusReason()?->value,
                ApplicationViews::time($company->statusChangedAt()),
                $company->mayOrder(),
            ),
            $draft === null ? null : $this->draft($draft, $lastSent, $companyTypes, $documentTypes),
            self::offered($companyTypes, false),
            self::offered($documentTypes, true),
            $company === null ? [] : array_map(
                fn (Application $sent): SentApplicationView => $this->sent($sent, $companyTypes, $documentTypes),
                $this->applications->historyOf($company->id()),
            ),
        );
    }

    /**
     * §4.3: the email first — Access's own banner — then whether an application was started.
     */
    private function stage(bool $emailVerified, ?Application $open): AccountStage
    {
        return match (true) {
            ! $emailVerified => AccountStage::EmailNotConfirmed,
            $open !== null => AccountStage::DraftOpen,
            default => AccountStage::NoApplication,
        };
    }

    /**
     * @param  array<string, CompanyType>  $companyTypes
     * @param  array<string, DocumentType>  $documentTypes
     */
    private function draft(Application $draft, ?Application $lastSent, array $companyTypes, array $documentTypes): DraftView
    {
        $type = $draft->type();
        // What the last rejection marked and asked for; nothing after an approval.
        $rejection = $lastSent?->state() === ApplicationState::Rejected ? $lastSent : null;

        return new DraftView(
            $draft->id(),
            ApplicationViews::applicationValues($draft, $companyTypes),
            $type?->typeId !== null && ! ($companyTypes[$type->typeId] ?? null)?->isActive(),
            ApplicationViews::files($draft->documents(), $documentTypes, markInactive: true),
            $rejection === null ? [] : ApplicationViews::flags($rejection->flags()),
            $rejection === null ? [] : ApplicationViews::requests($rejection->requests()),
            ApplicationViews::answers($draft->answers()),
        );
    }

    /**
     * @param  array<string, CompanyType>  $companyTypes
     * @param  array<string, DocumentType>  $documentTypes
     */
    private function sent(Application $sent, array $companyTypes, array $documentTypes): SentApplicationView
    {
        return new SentApplicationView(
            $sent->id(),
            $sent->state()->value,
            ApplicationViews::applicationValues($sent, $companyTypes),
            ApplicationViews::time($sent->submittedAt()),
            ApplicationViews::time($sent->decidedAt()),
            $sent->decisionReason()?->value,
            ApplicationViews::files($sent->documents(), $documentTypes, markInactive: false),
            ApplicationViews::flags($sent->flags()),
            ApplicationViews::requests($sent->requests()),
            ApplicationViews::answers($sent->answers()),
        );
    }

    /**
     * What the form offers, in the list's own order: active types, and deactivated ones to be shown
     * greyed out, marked; a hidden one is not offered at all (amendment 5).
     *
     * @param  array<string, CompanyType|DocumentType>  $types
     * @return list<TypeOption>
     */
    private static function offered(array $types, bool $documents): array
    {
        $offered = [];

        foreach ($types as $type) {
            $greyed = ! $type->isActive();

            if ($greyed && $type->inactiveDisplay() !== InactiveTypeDisplay::Greyed) {
                continue;
            }

            $offered[] = new TypeOption(
                $type->id(),
                $type->name()->ar,
                $type->name()->en,
                $greyed,
                $documents && $type instanceof DocumentType && $type->isAskedFor(),
            );
        }

        return $offered;
    }
}

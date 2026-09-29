<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RejectCompany;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\B2B\Application\Audit\StaffCompanyAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Events\CompanyEvents;
use Modules\B2B\Application\Staff\CompanyMessages;
use Modules\B2B\Application\Staff\StaffCompanyAction;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`RejectCompany`** (b2b.md §1.2, §3.2, §4.1, amendment 4): decides the application a `PENDING`
 * company sent — the company is `REJECTED`, with the reason it is told. Staff may also flag items
 * sent wrong, which the next draft must replace, and ask this company for extra items, which the next
 * draft must answer. Only a rejection can: there is no status for "waiting for the company".
 *
 * Audited on the application — the reason, the flags and the requests by value, being staff's own
 * words; the customer is emailed the reason once the rejection has committed.
 */
final readonly class RejectCompanyHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_REVIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCompanyAction $action,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private CompanyMessages $messages,
        private CompanyEvents $events,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CompanyNotFound|InvalidCompanyAttribute|InvalidCompanyStatus|Unauthorized
     */
    public function handle(RejectCompany $command): void
    {
        [$scope, $found] = $this->action->about(self::PERMISSION, $command->companyId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $staffId = $this->action->decider(self::PERMISSION);
        $reason = Remark::of('reason', $command->reason);
        $flags = self::flags($command->flaggedFields, $command->flaggedDocumentTypeIds);
        $requests = self::requests($command->requests);

        $this->db->transaction(function () use ($found, $staffId, $reason, $flags, $requests): void {
            $this->applications->lockAccount($found->customerId());
            $company = $this->companies->forCustomerLocked($found->customerId()) ?? throw new CompanyNotFound;
            $waiting = $this->applications->openFor($company->customerId());

            if ($company->status() !== CompanyStatus::Pending || $waiting?->state() !== ApplicationState::Submitted) {
                throw new InvalidCompanyStatus('rejected', $company->status()->value);
            }

            $now = CarbonImmutable::now();
            $waiting->reject($staffId, $reason, $now, $flags, $requests);
            $company->reject($staffId, $reason, $now);

            $this->applications->update($waiting);
            $this->companies->update($company);
            $this->platform->recordAudit(StaffCompanyAudit::rejected($waiting, $reason, $waiting->flags(), $waiting->requests(), $company->homeStoreId()));
            $this->events->statusChanged($company, CompanyStatus::Pending);
            $this->messages->afterCommit($company->customerId(), static fn (SecurityMessages $messages, CustomerDto $customer) => $messages->companyRejected($customer, $reason->value));
        }, 3);
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $documentTypeIds
     * @return list<ApplicationFlag>
     *
     * @throws InvalidCompanyAttribute
     */
    private static function flags(array $fields, array $documentTypeIds): array
    {
        $flags = [];

        foreach ($fields as $field) {
            $flags[] = ApplicationFlag::field(FlaggedField::tryFrom($field) ?? throw new InvalidCompanyAttribute('flags', 'one of the five fields'));
        }

        foreach ($documentTypeIds as $documentTypeId) {
            $flags[] = ApplicationFlag::document($documentTypeId);
        }

        return $flags;
    }

    /**
     * @param  list<array{kind: string, label: string}>  $requests
     * @return list<ApplicationRequest>
     *
     * @throws InvalidCompanyAttribute
     */
    private static function requests(array $requests): array
    {
        $made = [];

        foreach ($requests as $position => $request) {
            $kind = RequestKind::tryFrom(strtoupper($request['kind'])) ?? throw new InvalidCompanyAttribute('requests', 'a text or a file');
            $made[] = ApplicationRequest::add(strtolower((string) Str::ulid()), $kind, $request['label'], $position);
        }

        return $made;
    }
}

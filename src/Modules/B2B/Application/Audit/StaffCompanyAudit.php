<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Audit;

use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * What staff's actions on a company leave in the audit log (b2b.md §3.2, amendment 10). Every one is
 * by the staff member, in the account's home store, inside the transaction of its change.
 *
 * **What staff write — a reason, a note, a request's label — and what they mark — the flagged fields
 * and document types — are recorded by value**, as Access records the reason a customer was
 * blocked. The company's own values stay "changed": a company typed under "Other" is the company's
 * words even when staff correct them. A listed type's id, the states and the statuses by value.
 *
 * Deciding sits on the **application** decided, as sending does; a status change, a type correction
 * and a paper opened sit on the **company**.
 */
final class StaffCompanyAudit
{
    private const string APPLICATION = 'b2b.application';

    private const string COMPANY = 'b2b.company';

    public static function approved(Application $application, ?Remark $note, string $homeStoreId): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('state', 'SUBMITTED', $application->state()->value)
            ->changed('company_status', CompanyStatus::Pending->value, CompanyStatus::Approved->value);

        if ($note !== null) {
            $changes->changed('note', null, $note->value);
        }

        return new AuditEntryDto('b2b.application.approved', self::APPLICATION, $application->id(), $homeStoreId, $changes);
    }

    /**
     * @param  list<ApplicationFlag>  $flags
     * @param  list<ApplicationRequest>  $requests
     */
    public static function rejected(Application $application, Remark $reason, array $flags, array $requests, string $homeStoreId): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('state', 'SUBMITTED', $application->state()->value)
            ->changed('company_status', CompanyStatus::Pending->value, CompanyStatus::Rejected->value)
            ->changed('reason', null, $reason->value);

        if ($flags !== []) {
            $changes->changed('flags', null, array_map(static fn (ApplicationFlag $flag): string => $flag->key(), $flags));
        }

        if ($requests !== []) {
            // The log takes a flat list: each request as its kind and the label staff wrote.
            $changes->changed('requests', null, array_map(
                static fn (ApplicationRequest $request): string => $request->kind->value.': '.$request->label,
                $requests,
            ));
        }

        return new AuditEntryDto('b2b.application.rejected', self::APPLICATION, $application->id(), $homeStoreId, $changes);
    }

    /**
     * Suspending or reinstating: the status from and to, and the reason, required for both (§1.1).
     */
    public static function statusChanged(string $action, Company $company, CompanyStatus $from, Remark $reason): AuditEntryDto
    {
        return new AuditEntryDto($action, self::COMPANY, $company->id(), $company->homeStoreId(), AuditChanges::none()
            ->changed('status', $from->value, $company->status()->value)
            ->changed('reason', null, $reason->value));
    }

    /**
     * A staff correction of the company's type — by the correction itself, or a type's holders moved
     * when it is deactivated with a replacement or transferred (§1.3, §3.2, amendment 11).
     */
    public static function typeChanged(string $action, Company $company, ?CompanyTypeChoice $from): AuditEntryDto
    {
        $to = $company->details()->type;
        $changes = AuditChanges::none();

        if ($from?->typeId !== null || $to->typeId !== null) {
            $changes->changed('company_type_id', $from?->typeId, $to->typeId);
        }

        if ($from?->other !== null || $to->other !== null) {
            $changes->personal('company_type_other');
        }

        return new AuditEntryDto($action, self::COMPANY, $company->id(), $company->homeStoreId(), $changes);
    }

    /**
     * A paper or a file answer opened by staff (amendment 10(f)): which application, and which
     * paper type or request — **never the file's id**, so the entry tells a reader without the
     * private-files permission nothing about which file exists (amendment 8(c)).
     */
    public static function documentOpened(Company $company, string $applicationId, ?string $documentTypeId, ?string $requestId): AuditEntryDto
    {
        $changes = AuditChanges::none()->changed('application_id', null, $applicationId);

        if ($documentTypeId !== null) {
            $changes->changed('document_type_id', null, $documentTypeId);
        }

        if ($requestId !== null) {
            $changes->changed('request_id', null, $requestId);
        }

        return new AuditEntryDto('b2b.company.document_opened', self::COMPANY, $company->id(), $company->homeStoreId(), $changes);
    }
}

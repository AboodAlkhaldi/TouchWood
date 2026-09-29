<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Audit;

use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * What the company's own actions leave in the audit log (b2b.md §3.1, amendments 4 and 5): **sending
 * an application, discarding a draft, and changing the address** — nothing else. Each draft save
 * and each upload is not audited: every one of those is part of an application that is then either
 * sent or discarded, and Platform keeps its own entries for the files.
 *
 * **Everything the customer typed is recorded only as "changed"; only the states, and a listed
 * company type's id, by value.** Sending and discarding sit on the application, the address change
 * on the company. Each entry belongs to the account's home store, as Access's entries about a
 * customer do, so that store's staff read it.
 */
final class CompanyAccountAudit
{
    private const string APPLICATION = 'b2b.application';

    private const string COMPANY = 'b2b.company';

    /**
     * @param  CompanyStatus|null  $before  the company's status before, or null when this sending created it
     */
    public static function submitted(Application $application, ?CompanyStatus $before, CompanyStatus $after, string $homeStoreId): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('state', 'DRAFT', $application->state()->value)
            ->changed('company_status', $before?->value, $after->value);

        foreach (['name' => $application->name(), 'cr_number' => $application->crNumber(), 'tax_number' => $application->taxNumber(), 'address' => $application->address(), 'note' => $application->note()] as $attribute => $value) {
            if ($value !== null) {
                $changes->personal($attribute);
            }
        }

        $type = $application->type();

        if ($type?->typeId !== null) {
            $changes->changed('company_type_id', null, $type->typeId);
        } elseif ($type !== null) {
            $changes->personal('company_type_other');
        }

        return new AuditEntryDto('b2b.application.submitted', self::APPLICATION, $application->id(), $homeStoreId, $changes);
    }

    public static function discarded(Application $draft, string $homeStoreId): AuditEntryDto
    {
        return new AuditEntryDto('b2b.application.discarded', self::APPLICATION, $draft->id(), $homeStoreId, AuditChanges::none()
            ->changed('state', $draft->state()->value, null));
    }

    public static function addressChanged(Company $company): AuditEntryDto
    {
        return new AuditEntryDto('b2b.company.address_changed', self::COMPANY, $company->id(), $company->homeStoreId(), AuditChanges::none()
            ->personal('address'));
    }
}

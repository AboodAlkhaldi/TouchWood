<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyDetails;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Public\Enums\CompanyStatus;
use stdClass;

final readonly class DatabaseCompanyRepository implements CompanyRepository
{
    private const string TABLE = 'b2b.companies';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $companyId): ?Company
    {
        return $this->one('id', $companyId, lock: false);
    }

    public function byId(string $companyId): ?Company
    {
        return $this->one('id', $companyId, lock: true);
    }

    public function forCustomer(string $customerId): ?Company
    {
        return $this->one('customer_id', $customerId, lock: false);
    }

    public function forCustomerLocked(string $customerId): ?Company
    {
        return $this->one('customer_id', $customerId, lock: true);
    }

    public function add(Company $company): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TABLE)->insert([
            'id' => $company->id(),
            'customer_id' => $company->customerId(),
            'home_store_id' => $company->homeStoreId(),
            ...self::toRow($company),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Company $company): void
    {
        $this->db->table(self::TABLE)->where('id', $company->id())->update([
            ...self::toRow($company),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function one(string $column, string $id, bool $lock): ?Company
    {
        if (! Ulids::valid($id)) {
            return null;
        }

        $query = $this->db->table(self::TABLE)->where($column, strtolower($id));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? self::toCompany($row) : null;
    }

    /**
     * @return array<string, string|CarbonImmutable|null>
     */
    private static function toRow(Company $company): array
    {
        $details = $company->details();

        return [
            'name' => $details->name->value,
            'company_type_id' => $details->type->typeId,
            'company_type_other' => $details->type->other,
            'cr_number' => $details->crNumber->value,
            'tax_number' => $details->taxNumber->value,
            'address' => $details->address->value,
            'status' => $company->status()->value,
            'status_before_suspension' => $company->statusBeforeSuspension()?->value,
            'status_reason' => $company->statusReason()?->value,
            'status_changed_at' => $company->statusChangedAt() === null ? null : CarbonImmutable::instance($company->statusChangedAt()),
            'status_changed_by' => $company->statusChangedBy(),
        ];
    }

    private static function toCompany(stdClass $row): Company
    {
        return Company::reconstitute(
            (string) $row->id,
            (string) $row->customer_id,
            (string) $row->home_store_id,
            new CompanyDetails(
                CompanyName::reconstitute((string) $row->name),
                CompanyTypeChoice::reconstitute(
                    $row->company_type_id === null ? null : (string) $row->company_type_id,
                    $row->company_type_other === null ? null : (string) $row->company_type_other,
                ),
                RegistrationNumber::reconstitute((string) $row->cr_number),
                RegistrationNumber::reconstitute((string) $row->tax_number),
                CompanyAddress::reconstitute((string) $row->address),
            ),
            CompanyStatus::from((string) $row->status),
            $row->status_before_suspension === null ? null : CompanyStatus::from((string) $row->status_before_suspension),
            $row->status_reason === null ? null : Remark::reconstitute((string) $row->status_reason),
            $row->status_changed_at === null ? null : CarbonImmutable::parse((string) $row->status_changed_at),
            $row->status_changed_by === null ? null : (string) $row->status_changed_by,
        );
    }
}

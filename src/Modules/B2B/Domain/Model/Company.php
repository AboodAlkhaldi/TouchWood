<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Model;

use DateTimeImmutable;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyDetails;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * The company behind a company account (b2b.md §1.1). One per account, valid in every store.
 *
 * **There is no company until an application is sent**, and it only ever changes its registered
 * details the same way: every application — the first, a reapplication after a rejection, new
 * details from an approved company — goes draft → sent → `PENDING` → decided (owner, 2026-09-27).
 * The company holds the latest values sent; each application keeps its own copy. Only the address
 * changes on its own, and a staff correction of the type.
 *
 * The state machine (b2b.md §4.1, amendment 3):
 *
 *   (none) → PENDING                 an application is sent
 *   PENDING → APPROVED | REJECTED    staff decide it
 *   APPROVED | REJECTED → PENDING    a new application is sent
 *   any but SUSPENDED → SUSPENDED    staff, with a reason; remembers where it came from
 *   SUSPENDED → what it was          staff reinstate, with a reason
 *
 * There is no way from APPROVED to REJECTED: rejecting decides a sent application, and an approved
 * company has none waiting. Staff who must stop one suspend it (owner, 2026-09-27).
 */
final class Company
{
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $id,
        private readonly string $customerId,
        private readonly string $homeStoreId,
        private CompanyName $name,
        private CompanyTypeChoice $type,
        private RegistrationNumber $crNumber,
        private RegistrationNumber $taxNumber,
        private CompanyAddress $address,
        private CompanyStatus $status,
        private ?CompanyStatus $statusBeforeSuspension,
        private ?Remark $statusReason,
        private ?DateTimeImmutable $statusChangedAt,
        private ?string $statusChangedBy,
    ) {}

    /**
     * The first application sent — the moment the company comes to exist (b2b.md §1.1).
     *
     * @param  string  $homeStoreId  the account's home store, whose staff review it (§3.2)
     */
    public static function fromFirstApplication(
        string $id,
        string $customerId,
        string $homeStoreId,
        CompanyDetails $details,
        DateTimeImmutable $at,
    ): self {
        return new self(
            $id, $customerId, $homeStoreId,
            $details->name, $details->type, $details->crNumber, $details->taxNumber, $details->address,
            CompanyStatus::Pending, null, null, $at, null,
        );
    }

    public static function reconstitute(
        string $id,
        string $customerId,
        string $homeStoreId,
        CompanyDetails $details,
        CompanyStatus $status,
        ?CompanyStatus $statusBeforeSuspension,
        ?Remark $statusReason,
        ?DateTimeImmutable $statusChangedAt,
        ?string $statusChangedBy,
    ): self {
        return new self(
            $id, $customerId, $homeStoreId,
            $details->name, $details->type, $details->crNumber, $details->taxNumber, $details->address,
            $status, $statusBeforeSuspension, $statusReason, $statusChangedAt, $statusChangedBy,
        );
    }

    /**
     * A later application sent: after a rejection, or with new details from an approved company.
     * Either way the company is `PENDING` again, and cannot order until staff decide — reapplying
     * never restores ordering in the meantime (handoff §8.2).
     *
     * @throws CompanySuspended a suspended company changes nothing staff approved (§1.1)
     * @throws InvalidCompanyStatus one application is already waiting
     */
    public function applyAgain(CompanyDetails $details, DateTimeImmutable $at): void
    {
        if ($this->status === CompanyStatus::Suspended) {
            throw new CompanySuspended;
        }

        if ($this->status === CompanyStatus::Pending) {
            throw new InvalidCompanyStatus('sent a new application', $this->status->value);
        }

        $this->replaceDetails($details);
        $this->changeStatus(CompanyStatus::Pending, null, null, $at);
    }

    /**
     * @throws InvalidCompanyStatus nothing is waiting to be decided
     */
    public function approve(string $staffId, DateTimeImmutable $at): void
    {
        $this->requireStatus('approved', CompanyStatus::Pending);
        // The approval's note, if staff wrote one, belongs to the application it decided; what the
        // company is told today is simply that it is approved.
        $this->changeStatus(CompanyStatus::Approved, null, $staffId, $at);
    }

    /**
     * @throws InvalidCompanyStatus nothing is waiting to be decided
     */
    public function reject(string $staffId, Remark $reason, DateTimeImmutable $at): void
    {
        $this->requireStatus('rejected', CompanyStatus::Pending);
        $this->changeStatus(CompanyStatus::Rejected, $reason, $staffId, $at);
    }

    /**
     * From any status (handoff §8.2), remembering which, so reinstating neither grants ordering
     * nobody decided to grant nor withdraws an approval nobody decided to withdraw (§4.1).
     *
     * @throws InvalidCompanyStatus it is suspended already
     */
    public function suspend(string $staffId, Remark $reason, DateTimeImmutable $at): void
    {
        if ($this->status === CompanyStatus::Suspended) {
            throw new InvalidCompanyStatus('suspended', $this->status->value);
        }

        $this->statusBeforeSuspension = $this->status;
        $this->changeStatus(CompanyStatus::Suspended, $reason, $staffId, $at);
    }

    /**
     * Back to the status it held when it was suspended — never simply `PENDING` (§4.1). A reason
     * too, so the history reads as a conversation (owner, 2026-09-26).
     *
     * @throws InvalidCompanyStatus it is not suspended
     */
    public function reinstate(string $staffId, Remark $reason, DateTimeImmutable $at): void
    {
        $this->requireStatus('reinstated', CompanyStatus::Suspended);

        // Always set while suspended — suspend() writes it and a CHECK holds the row to it. The
        // fallback only satisfies the type, and is the one status that grants nothing.
        $before = $this->statusBeforeSuspension ?? CompanyStatus::Pending;
        $this->statusBeforeSuspension = null;
        $this->changeStatus($before, $reason, $staffId, $at);
    }

    /**
     * The address changes freely, in every status — suspended included (§1.1). It is not one of
     * the details staff approve.
     */
    public function moveTo(CompanyAddress $address): void
    {
        if ($address->equals($this->address)) {
            return;
        }

        $this->address = $address;
        $this->markChanged('address');
    }

    /**
     * Staff putting the type right — an "Other" in better words, or a listed type the company
     * should have chosen (owner, 2026-09-27). It corrects the company only: the application keeps
     * what was sent, and the company does not go back to `PENDING` for a staff member's own fix.
     */
    public function correctType(CompanyTypeChoice $type): void
    {
        if ($type->equals($this->type)) {
            return;
        }

        $this->type = $type;
        $this->markChanged('company_type');
    }

    /** Sales's half of the ordering rule (handoff §7.4): approved, and nothing else. */
    public function mayOrder(): bool
    {
        return $this->status === CompanyStatus::Approved;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function homeStoreId(): string
    {
        return $this->homeStoreId;
    }

    public function details(): CompanyDetails
    {
        return new CompanyDetails($this->name, $this->type, $this->crNumber, $this->taxNumber, $this->address);
    }

    public function status(): CompanyStatus
    {
        return $this->status;
    }

    public function statusBeforeSuspension(): ?CompanyStatus
    {
        return $this->statusBeforeSuspension;
    }

    public function statusReason(): ?Remark
    {
        return $this->statusReason;
    }

    public function statusChangedAt(): ?DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    /** The staff member who last changed the status; null when the customer's sending did. */
    public function statusChangedBy(): ?string
    {
        return $this->statusChangedBy;
    }

    /**
     * @return list<string> what changed since this was read, for the audit log
     */
    public function pullChanges(): array
    {
        $changed = $this->changed;
        $this->changed = [];

        return $changed;
    }

    private function replaceDetails(CompanyDetails $details): void
    {
        if ($details->name->value !== $this->name->value) {
            $this->name = $details->name;
            $this->markChanged('name');
        }

        if (! $details->type->equals($this->type)) {
            $this->type = $details->type;
            $this->markChanged('company_type');
        }

        if ($details->crNumber->value !== $this->crNumber->value) {
            $this->crNumber = $details->crNumber;
            $this->markChanged('cr_number');
        }

        if ($details->taxNumber->value !== $this->taxNumber->value) {
            $this->taxNumber = $details->taxNumber;
            $this->markChanged('tax_number');
        }

        $this->moveTo($details->address);
    }

    private function changeStatus(CompanyStatus $status, ?Remark $reason, ?string $staffId, DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->statusReason = $reason;
        $this->statusChangedAt = $at;
        $this->statusChangedBy = $staffId;
        $this->markChanged('status');
    }

    /**
     * @throws InvalidCompanyStatus
     */
    private function requireStatus(string $change, CompanyStatus $required): void
    {
        if ($this->status !== $required) {
            throw new InvalidCompanyStatus($change, $this->status->value);
        }
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }
}

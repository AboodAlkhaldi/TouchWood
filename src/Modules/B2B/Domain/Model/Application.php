<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Model;

use DateTimeImmutable;
use LogicException;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Exception\MissingRequiredDocument;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\AttachedDocument;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyDetails;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;

/**
 * One application: what a company sent, when, and what staff decided (b2b.md §1.2). **Every
 * application is kept**, and each is a snapshot — its own copy of every value sent — so staff can
 * compare what was rejected with what has been sent now, and an approval stays a decision about
 * particular values.
 *
 * It belongs to the **account**: a first draft has no company, because there is none until it is
 * sent (§1.1). One open application — a draft or a sent one — per account at a time.
 *
 *   DRAFT → SUBMITTED → APPROVED | REJECTED     (§4.2)
 *
 * Only a draft changes. A draft may hold any value empty; sending needs them all.
 */
final class Application
{
    /** @var list<string> */
    private array $changed = [];

    /**
     * @param  array<string, AttachedDocument>  $documents  by document type id: one file per type
     */
    private function __construct(
        private readonly string $id,
        private readonly string $customerId,
        private ?string $companyId,
        private ApplicationState $state,
        private ?CompanyName $name,
        private ?CompanyTypeChoice $type,
        private ?RegistrationNumber $crNumber,
        private ?RegistrationNumber $taxNumber,
        private ?CompanyAddress $address,
        private ?Remark $note,
        private array $documents,
        private ?DateTimeImmutable $submittedAt,
        private ?DateTimeImmutable $decidedAt,
        private ?string $decidedBy,
        private ?Remark $decisionReason,
    ) {}

    /**
     * A new, empty draft. $companyId is the company it will update, or null for the account's first.
     */
    public static function draft(string $id, string $customerId, ?string $companyId): self
    {
        return new self($id, $customerId, $companyId, ApplicationState::Draft, null, null, null, null, null, null, [], null, null, null, null);
    }

    /**
     * @param  array<string, AttachedDocument>  $documents
     */
    public static function reconstitute(
        string $id,
        string $customerId,
        ?string $companyId,
        ApplicationState $state,
        ?CompanyName $name,
        ?CompanyTypeChoice $type,
        ?RegistrationNumber $crNumber,
        ?RegistrationNumber $taxNumber,
        ?CompanyAddress $address,
        ?Remark $note,
        array $documents,
        ?DateTimeImmutable $submittedAt,
        ?DateTimeImmutable $decidedAt,
        ?string $decidedBy,
        ?Remark $decisionReason,
    ): self {
        return new self($id, $customerId, $companyId, $state, $name, $type, $crNumber, $taxNumber, $address, $note, $documents, $submittedAt, $decidedAt, $decidedBy, $decisionReason);
    }

    /**
     * The wizard, saved as it goes (§1.2): whatever the customer has filled in so far, a null for
     * anything left empty.
     *
     * @throws ApplicationNotEditable
     */
    public function describe(
        ?CompanyName $name,
        ?CompanyTypeChoice $type,
        ?RegistrationNumber $crNumber,
        ?RegistrationNumber $taxNumber,
        ?CompanyAddress $address,
        ?Remark $note,
    ): void {
        $this->requireDraft();

        $this->name = $name;
        $this->type = $type;
        $this->crNumber = $crNumber;
        $this->taxNumber = $taxNumber;
        $this->address = $address;
        $this->note = $note;
        $this->markChanged('details');
    }

    /**
     * One file under one type; a second upload replaces the first (owner, 2026-09-27).
     *
     * @return string|null the media id it replaced, for the caller to let go of
     *
     * @throws ApplicationNotEditable
     */
    public function attach(string $documentTypeId, string $mediaId, DateTimeImmutable $at): ?string
    {
        $this->requireDraft();

        $replaced = $this->documents[$documentTypeId]->mediaId ?? null;
        $this->documents[$documentTypeId] = new AttachedDocument($documentTypeId, $mediaId, $at);
        $this->markChanged('documents');

        return $replaced;
    }

    /**
     * @return string|null the media id it removed, or null when that type had none
     *
     * @throws ApplicationNotEditable
     */
    public function detach(string $documentTypeId): ?string
    {
        $this->requireDraft();

        $removed = $this->documents[$documentTypeId]->mediaId ?? null;

        if ($removed !== null) {
            unset($this->documents[$documentTypeId]);
            $this->markChanged('documents');
        }

        return $removed;
    }

    /**
     * Sends it (§4.2). Everything must be there; a listed company type must still be offered — a
     * draft that chose one staff have deactivated since chooses again (owner, 2026-09-27) — and
     * every document type that is required and offered must have its file.
     *
     * A document under a type staff have deactivated since goes with it: it was uploaded, and it is
     * evidence like any other. Nothing about the account is checked here — the confirmed email is
     * Access's, and the use case asks it (§1.2).
     *
     * @param  string  $companyId  the company this application creates or updates
     * @param  list<CompanyType>  $offeredTypes  the company types a new application may choose
     * @param  list<DocumentType>  $documentTypes  every document type, active or not
     * @return CompanyDetails what the company now holds
     *
     * @throws ApplicationNotEditable
     * @throws InvalidCompanyAttribute a value is missing
     * @throws CompanyTypeInactive
     * @throws MissingRequiredDocument
     */
    public function submit(string $companyId, array $offeredTypes, array $documentTypes, DateTimeImmutable $at): CompanyDetails
    {
        $this->requireDraft();

        $details = new CompanyDetails(
            $this->name ?? throw new InvalidCompanyAttribute('name', 'required'),
            $this->type ?? throw new InvalidCompanyAttribute('company_type', 'required'),
            $this->crNumber ?? throw new InvalidCompanyAttribute('cr_number', 'required'),
            $this->taxNumber ?? throw new InvalidCompanyAttribute('tax_number', 'required'),
            $this->address ?? throw new InvalidCompanyAttribute('address', 'required'),
        );

        $typeId = $details->type->typeId;

        if ($typeId !== null && ! in_array($typeId, array_map(static fn (CompanyType $type): string => $type->id(), $offeredTypes), true)) {
            throw new CompanyTypeInactive;
        }

        foreach ($documentTypes as $documentType) {
            if ($documentType->isAskedFor() && ! isset($this->documents[$documentType->id()])) {
                throw new MissingRequiredDocument($documentType->id());
            }
        }

        // A draft started for a company stays that company's: a handler passing another is a bug,
        // and would move the account's history onto somebody else's company.
        if ($this->companyId !== null && $this->companyId !== $companyId) {
            throw new LogicException('A draft for one company cannot be sent as another\'s.');
        }

        $this->companyId = $companyId;
        $this->state = ApplicationState::Submitted;
        $this->submittedAt = $at;
        $this->markChanged('state');

        return $details;
    }

    /**
     * @param  Remark|null  $note  optional; the screen tells staff it is emailed (owner, 2026-09-27)
     *
     * @throws InvalidCompanyStatus it is not waiting for a decision
     */
    public function approve(string $staffId, ?Remark $note, DateTimeImmutable $at): void
    {
        $this->decide(ApplicationState::Approved, 'approved', $staffId, $note, $at);
    }

    /**
     * @throws InvalidCompanyStatus it is not waiting for a decision
     */
    public function reject(string $staffId, Remark $reason, DateTimeImmutable $at): void
    {
        $this->decide(ApplicationState::Rejected, 'rejected', $staffId, $reason, $at);
    }

    /**
     * A draft may be thrown away by the customer, files and all (owner, 2026-09-27); nothing sent
     * ever is.
     *
     * @throws ApplicationNotEditable
     */
    public function ensureDiscardable(): void
    {
        $this->requireDraft();
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function companyId(): ?string
    {
        return $this->companyId;
    }

    public function state(): ApplicationState
    {
        return $this->state;
    }

    public function name(): ?CompanyName
    {
        return $this->name;
    }

    public function type(): ?CompanyTypeChoice
    {
        return $this->type;
    }

    public function crNumber(): ?RegistrationNumber
    {
        return $this->crNumber;
    }

    public function taxNumber(): ?RegistrationNumber
    {
        return $this->taxNumber;
    }

    public function address(): ?CompanyAddress
    {
        return $this->address;
    }

    public function note(): ?Remark
    {
        return $this->note;
    }

    /**
     * @return array<string, AttachedDocument> by document type id
     */
    public function documents(): array
    {
        return $this->documents;
    }

    public function submittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function decidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function decidedBy(): ?string
    {
        return $this->decidedBy;
    }

    /** The rejection's reason, or the approval's note when staff wrote one (amendment 1). */
    public function decisionReason(): ?Remark
    {
        return $this->decisionReason;
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

    /**
     * @throws InvalidCompanyStatus
     */
    private function decide(ApplicationState $decision, string $change, string $staffId, ?Remark $reason, DateTimeImmutable $at): void
    {
        if ($this->state !== ApplicationState::Submitted) {
            throw new InvalidCompanyStatus($change, $this->state->value);
        }

        $this->state = $decision;
        $this->decidedAt = $at;
        $this->decidedBy = $staffId;
        $this->decisionReason = $reason;
        $this->markChanged('state');
    }

    /**
     * @throws ApplicationNotEditable
     */
    private function requireDraft(): void
    {
        if ($this->state !== ApplicationState::Draft) {
            throw new ApplicationNotEditable($this->state->value);
        }
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }
}

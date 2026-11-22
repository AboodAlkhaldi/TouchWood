<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Model;

use DateTimeImmutable;
use LogicException;
use Modules\B2B\Domain\Exception\AnswerKindMismatch;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\DocumentNoLongerAccepted;
use Modules\B2B\Domain\Exception\FlaggedItemNotReplaced;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Exception\MissingRequiredDocument;
use Modules\B2B\Domain\Exception\RequestNotAnswered;
use Modules\B2B\Domain\Exception\RequestNotFound;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationReference;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\AttachedDocument;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyDetails;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyText;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\RequestAnswer;

/**
 * One application: what a company sent, when, and what staff decided (b2b.md §1.2). **Every
 * application is kept**, and each is a snapshot — its own copy of every value sent — so staff can
 * compare what was rejected with what has been sent now, and an approval stays a decision about
 * particular values.
 *
 * It belongs to the **account and a store** (amendments 18–20): a first draft has no company,
 * because there is none until it is sent (§1.1), so the application carries the store it was made
 * in. One open application — a draft or a sent one — per account **and store** at a time.
 *
 *   DRAFT → SUBMITTED → APPROVED | REJECTED     (§4.2)
 *
 * Only a draft changes. A draft may hold any value empty; sending needs them all.
 *
 * **A rejection can say what to fix and what to add** (amendment 4), and only a rejection: staff
 * flag items sent wrong, and request extra text answers or files from this one company. Those
 * belong to the rejected application, as part of what it was told; the next draft answers the
 * requests — the answers are that draft's, as every value it sends — and cannot be sent until every
 * flagged item is replaced and every request answered.
 */
final class Application
{
    /** @var list<string> */
    private array $changed = [];

    /**
     * @param  array<string, AttachedDocument>  $documents  by document type id: one file per type
     * @param  array<string, ApplicationFlag>  $flags  by ApplicationFlag::key(): one per item
     * @param  array<string, ApplicationRequest>  $requests  by request id
     * @param  array<string, RequestAnswer>  $answers  by request id: one answer per request
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
        private ?ApplicationReference $reference,
        private ?DateTimeImmutable $decidedAt,
        private ?string $decidedBy,
        private ?Remark $decisionReason,
        private array $flags,
        private array $requests,
        private array $answers,
        private readonly string $storeId,
    ) {}

    /**
     * A new, empty draft in a store. $companyId is the company it will update, or null for the
     * account's first in that store.
     */
    public static function draft(string $id, string $customerId, ?string $companyId, string $storeId): self
    {
        return new self($id, $customerId, $companyId, ApplicationState::Draft, null, null, null, null, null, null, [], null, null, null, null, null, [], [], [], strtolower($storeId));
    }

    /**
     * A later draft of an existing company (amendment 4): it opens with **the details the company
     * holds now** — so an address change or a staff correction of the type since is not lost — and
     * **the files of the last application it sent**, already under their types with the dates they
     * were uploaded. The company then replaces only what the rejection was about; the application it
     * copies from keeps its own copies untouched. The note starts empty: each application's is its own.
     *
     * @param  Application|null  $lastSent  the company's last application sent, if it has one
     */
    public static function draftFor(string $id, Company $company, ?Application $lastSent): self
    {
        if ($lastSent !== null && $lastSent->companyId !== $company->id()) {
            throw new LogicException('A draft of one company cannot start from another company\'s application.');
        }

        $details = $company->details();

        return new self(
            $id, $company->customerId(), $company->id(), ApplicationState::Draft,
            $details->name, $details->type, $details->crNumber, $details->taxNumber, $details->address, null,
            $lastSent === null ? [] : $lastSent->documents,
            null, null, null, null, null, [], [], [],
            // The company's store: a company is reapplied for where it is (amendment 18).
            strtolower($company->homeStoreId()),
        );
    }

    /**
     * @param  array<string, AttachedDocument>  $documents
     * @param  list<ApplicationFlag>  $flags  what the rejection of this application marked
     * @param  list<ApplicationRequest>  $requests  what the rejection of this application asked for
     * @param  list<RequestAnswer>  $answers  this application's answers to the requests before it
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
        ?ApplicationReference $reference,
        ?DateTimeImmutable $decidedAt,
        ?string $decidedBy,
        ?Remark $decisionReason,
        array $flags,
        array $requests,
        array $answers,
        string $storeId,
    ): self {
        // A sent application always has its number, and a draft never one (§1.2); the database's
        // CHECK holds the same.
        if (($state === ApplicationState::Draft) !== ($reference === null)) {
            throw new LogicException("Application {$id} is {$state->value} and ".($reference === null ? 'has no reference.' : 'already has one.'));
        }

        $byRequest = [];

        foreach ($answers as $answer) {
            $byRequest[$answer->requestId] = $answer;
        }

        return new self(
            $id, $customerId, $companyId, $state, $name, $type, $crNumber, $taxNumber, $address, $note, $documents,
            $submittedAt, $reference, $decidedAt, $decidedBy, $decisionReason, self::byKey($flags), self::byId($requests), $byRequest,
            strtolower($storeId),
        );
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
     * Answers one request of the last rejection (amendments 4 and 5), with what it asks for: text,
     * or a file. A second answer to the same request replaces the first.
     *
     * @param  Application|null  $lastSent  the account's last application sent, if any
     * @return string|null the file the new answer replaced, for the caller to let go of; null when
     *                     it replaced text or nothing
     *
     * @throws ApplicationNotEditable
     * @throws RequestNotFound the last application sent was not rejected, or made no such request
     * @throws AnswerKindMismatch
     */
    public function answer(?Application $lastSent, string $requestId, RequestAnswer $answer): ?string
    {
        $this->requireDraft();
        $this->requireLastSentOfThisCompany($lastSent);

        $requestId = strtolower($requestId);

        if ($answer->requestId !== $requestId) {
            throw new LogicException('An answer given for one request cannot be kept under another.');
        }

        if ($lastSent === null || $lastSent->state !== ApplicationState::Rejected) {
            throw new RequestNotFound($requestId);
        }

        $request = $lastSent->requests[$requestId] ?? throw new RequestNotFound($requestId);

        if ($answer->kind() !== $request->kind) {
            throw new AnswerKindMismatch($request->kind->value, $answer->kind()->value);
        }

        $replaced = $this->answers[$requestId]->mediaId ?? null;
        $this->answers[$requestId] = $answer;
        $this->markChanged('answers');

        return $replaced;
    }

    /**
     * The mirror of detach(): nothing blocks the company taking an answer out of its own draft.
     *
     * @return string|null the file it removed, for the caller to let go of; null for a text answer
     *                     or none
     *
     * @throws ApplicationNotEditable
     */
    public function removeAnswer(string $requestId): ?string
    {
        $this->requireDraft();

        $requestId = strtolower($requestId);

        if (! isset($this->answers[$requestId])) {
            return null;
        }

        $removed = $this->answers[$requestId]->mediaId;
        unset($this->answers[$requestId]);
        $this->markChanged('answers');

        return $removed;
    }

    /**
     * Sends it (§4.2). Refused, in this order (amendments 2, 4, 5 and 6):
     *
     * 1. a value is missing;
     * 2. a listed company type is not one of the home store's (InvalidCompanyAttribute), or is one
     *    staff have deactivated since the draft chose it (CompanyTypeInactive) — "Other" is not a row
     *    and is always taken;
     * 3. a file sits under a document type staff have deactivated since (DocumentNoLongerAccepted):
     *    the draft never sends anything deactivated;
     * 4. a document type that is required and offered has no file;
     * 5. after a rejection, a flagged field holds what was sent (exactly, after trimming);
     * 6. after a rejection, a flagged document has no file, or the file that was sent — unless its
     *    type is no longer offered, when the flag stops blocking;
     * 7. after a rejection, a request has no answer.
     *
     * Nothing about the account is checked here — the confirmed email is Access's, and the use case
     * asks it (§1.2).
     *
     * @param  string  $companyId  the company this application creates or updates
     * @param  list<CompanyType>  $companyTypes  every company type of the home store, active or not
     * @param  list<DocumentType>  $documentTypes  every document type of the home store, active or not
     * @param  Application|null  $lastSent  the account's last application sent; its flags and
     *                                      requests apply only when it was rejected
     * @param  ApplicationReference  $reference  its number (amendment 14(g)), taken in the send's own
     *                                           transaction so a refused send gives it back
     * @return CompanyDetails what the company now holds
     *
     * @throws ApplicationNotEditable
     * @throws InvalidCompanyAttribute a value is missing, or the type is not the home store's
     * @throws CompanyTypeInactive
     * @throws DocumentNoLongerAccepted
     * @throws MissingRequiredDocument
     * @throws FlaggedItemNotReplaced
     * @throws RequestNotAnswered
     */
    public function submit(string $companyId, array $companyTypes, array $documentTypes, ?Application $lastSent, DateTimeImmutable $at, ApplicationReference $reference): CompanyDetails
    {
        $this->requireDraft();
        $this->requireLastSentOfThisCompany($lastSent);

        $details = new CompanyDetails(
            $this->name ?? throw new InvalidCompanyAttribute('name', 'required'),
            $this->type ?? throw new InvalidCompanyAttribute('company_type', 'required'),
            $this->crNumber ?? throw new InvalidCompanyAttribute('cr_number', 'required'),
            $this->taxNumber ?? throw new InvalidCompanyAttribute('tax_number', 'required'),
            $this->address ?? throw new InvalidCompanyAttribute('address', 'required'),
        );

        self::requireOffered($details->type, $companyTypes);

        $types = [];

        foreach ($documentTypes as $documentType) {
            $types[$documentType->id()] = $documentType;
        }

        foreach (array_keys($this->documents) as $typeId) {
            $type = $types[$typeId] ?? throw new LogicException("A document is held under \"{$typeId}\", which is not one of the home store's document types.");

            if (! $type->isActive()) {
                throw new DocumentNoLongerAccepted($typeId);
            }
        }

        foreach ($documentTypes as $documentType) {
            if ($documentType->isAskedFor() && ! isset($this->documents[$documentType->id()])) {
                throw new MissingRequiredDocument($documentType->id());
            }
        }

        if ($lastSent !== null && $lastSent->state === ApplicationState::Rejected) {
            $this->requireFlagsReplaced($lastSent, $types);

            foreach ($lastSent->requests() as $request) {
                if (! isset($this->answers[$request->id])) {
                    throw new RequestNotAnswered($request->id);
                }
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
        $this->reference = $reference;
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
     * Rejects it with a reason, and optionally says exactly what to fix and what to add (amendment
     * 4): flags on items it sent wrong, and requests for extra items. They are kept only once the
     * rejection itself succeeds.
     *
     * @param  list<ApplicationFlag>  $flags  any of the five fields; a document only if the
     *                                        application sent a file under its type. The same flag
     *                                        twice is kept once.
     * @param  list<ApplicationRequest>  $requests
     *
     * @throws InvalidCompanyAttribute a flag on a document the application did not send
     * @throws InvalidCompanyStatus it is not waiting for a decision
     */
    public function reject(string $staffId, Remark $reason, DateTimeImmutable $at, array $flags = [], array $requests = []): void
    {
        foreach ($flags as $flag) {
            // Code-only: the database cannot see that the flagged type is one this application
            // sent a file under (amendment 6(c)).
            if ($flag->documentTypeId !== null && ! isset($this->documents[$flag->documentTypeId])) {
                throw new InvalidCompanyAttribute('flags', 'only a document the application sent');
            }
        }

        $flags = self::byKey($flags);
        $requests = self::byId($requests);

        $this->decide(ApplicationState::Rejected, 'rejected', $staffId, $reason, $at);

        $this->flags = $flags;
        $this->requests = $requests;
    }

    /**
     * The account behind it was anonymized (b2b.md §1.1, amendment 12(a)). **A sent application**
     * keeps its state, its type, its dates, its decision and staff's flags and requests, and gives up
     * the rest: the name, the CR number, the tax number, the address and an "Other" type's own words
     * become the company's placeholders (amendment 13(a)), the note and every answer go, and so do its
     * papers. A draft is not kept this way — it is deleted whole, as discarding it would.
     *
     * @return list<string> the files it let go of, for the caller to delete; empty a second time
     */
    public function anonymize(): array
    {
        if ($this->state === ApplicationState::Draft) {
            throw new LogicException('A draft is deleted whole when its account is anonymized, not kept with placeholders.');
        }

        $released = [];

        foreach ($this->documents as $document) {
            $released[] = $document->mediaId;
        }

        foreach ($this->answers as $answer) {
            if ($answer->mediaId !== null) {
                $released[] = $answer->mediaId;
            }
        }

        $other = $this->type?->isOther() === true;
        $placeholders = [
            $this->name?->value !== Company::DELETED_NAME,
            $this->crNumber?->value !== Company::DELETED,
            $this->taxNumber?->value !== Company::DELETED,
            $this->address?->value !== Company::DELETED,
            $this->note !== null,
            // An "Other" company's own words for its type (amendment 13(a)); a listed type stays.
            $other && $this->type->other !== Company::DELETED,
        ];

        if (in_array(true, $placeholders, true)) {
            $this->name = CompanyName::of(Company::DELETED_NAME);
            $this->crNumber = RegistrationNumber::of('cr_number', Company::DELETED);
            $this->taxNumber = RegistrationNumber::of('tax_number', Company::DELETED);
            $this->address = CompanyAddress::of(Company::DELETED);
            $this->type = $other ? CompanyTypeChoice::other(Company::DELETED) : $this->type;
            $this->note = null;
            $this->markChanged('details');
        }

        if ($this->documents !== []) {
            $this->documents = [];
            $this->markChanged('documents');
        }

        if ($this->answers !== []) {
            $this->answers = [];
            $this->markChanged('answers');
        }

        return $released;
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

    /**
     * The store the application was made in, and whose company it is or will be (amendment 18).
     */
    public function storeId(): string
    {
        return $this->storeId;
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

    /** Its number, from the moment it is sent; a draft has none (amendment 14(g)). */
    public function reference(): ?ApplicationReference
    {
        return $this->reference;
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
     * What the rejection of this application marked; empty for any other.
     *
     * @return list<ApplicationFlag>
     */
    public function flags(): array
    {
        return array_values($this->flags);
    }

    /**
     * What the rejection of this application asked for, by position, then id; empty for any other.
     *
     * @return list<ApplicationRequest>
     */
    public function requests(): array
    {
        $requests = array_values($this->requests);
        usort($requests, static fn (ApplicationRequest $a, ApplicationRequest $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);

        return $requests;
    }

    /**
     * This application's answers to the requests of the rejection before it.
     *
     * @return array<string, RequestAnswer> by request id
     */
    public function answers(): array
    {
        return $this->answers;
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
     * @param  list<CompanyType>  $companyTypes
     *
     * @throws InvalidCompanyAttribute
     * @throws CompanyTypeInactive
     */
    private static function requireOffered(CompanyTypeChoice $type, array $companyTypes): void
    {
        if ($type->typeId === null) {
            return;
        }

        foreach ($companyTypes as $companyType) {
            if ($companyType->id() === $type->typeId) {
                if (! $companyType->isActive()) {
                    throw new CompanyTypeInactive;
                }

                return;
            }
        }

        // Code-only: the database cannot see that the type belongs to the home store's list
        // (amendment 6(c)); a type of another store is as unknown as one that does not exist (6(d)).
        throw new InvalidCompanyAttribute('company_type', "not one of the home store's types");
    }

    /**
     * @param  array<string, DocumentType>  $types  the home store's document types, by id
     *
     * @throws FlaggedItemNotReplaced
     */
    private function requireFlagsReplaced(Application $lastSent, array $types): void
    {
        foreach ($lastSent->flags as $flag) {
            if ($flag->field !== null && $this->sentValue($flag->field) === $lastSent->sentValue($flag->field)) {
                throw new FlaggedItemNotReplaced($flag->field->value);
            }
        }

        foreach ($lastSent->flags as $flag) {
            $typeId = $flag->documentTypeId;

            if ($typeId === null) {
                continue;
            }

            // A type no longer offered asks for nothing, so its flag stops blocking (amendment 5).
            if (isset($types[$typeId]) && ! $types[$typeId]->isActive()) {
                continue;
            }

            $now = $this->documents[$typeId]->mediaId ?? null;

            if ($now === null || $now === ($lastSent->documents[$typeId]->mediaId ?? null)) {
                throw new FlaggedItemNotReplaced($typeId);
            }
        }
    }

    /**
     * One field as it would be sent, for telling whether a flagged one was replaced: a different
     * value exactly, after trimming (amendment 5) — letter case counts.
     */
    private function sentValue(FlaggedField $field): ?string
    {
        $value = match ($field) {
            FlaggedField::Name => $this->name?->value,
            FlaggedField::CompanyType => $this->type === null ? null : ($this->type->typeId !== null ? 'listed:'.$this->type->typeId : 'other:'.$this->type->other),
            FlaggedField::CrNumber => $this->crNumber?->value,
            FlaggedField::TaxNumber => $this->taxNumber?->value,
            FlaggedField::Address => $this->address?->value,
        };

        // Compared after the page's and the server's one trim (amendment 17(a)).
        return $value === null ? null : CompanyText::trimmed($value);
    }

    /**
     * The last application sent must be this company's, and decided: while one is waiting, no
     * draft can be open beside it. Anything else is a caller's bug, not the customer's mistake.
     */
    private function requireLastSentOfThisCompany(?Application $lastSent): void
    {
        if ($lastSent === null) {
            return;
        }

        if ($lastSent->companyId === null || $lastSent->companyId !== $this->companyId) {
            throw new LogicException('The last application sent belongs to another company.');
        }

        if ($lastSent->state !== ApplicationState::Approved && $lastSent->state !== ApplicationState::Rejected) {
            throw new LogicException('The last application sent is not decided yet.');
        }
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

    /**
     * @param  list<ApplicationFlag>  $flags
     * @return array<string, ApplicationFlag> one per item: a repeated flag is kept once
     */
    private static function byKey(array $flags): array
    {
        $keyed = [];

        foreach ($flags as $flag) {
            $keyed[$flag->key()] = $flag;
        }

        return $keyed;
    }

    /**
     * @param  list<ApplicationRequest>  $requests
     * @return array<string, ApplicationRequest>
     */
    private static function byId(array $requests): array
    {
        $keyed = [];

        foreach ($requests as $request) {
            $keyed[$request->id] = $request;
        }

        return $keyed;
    }
}

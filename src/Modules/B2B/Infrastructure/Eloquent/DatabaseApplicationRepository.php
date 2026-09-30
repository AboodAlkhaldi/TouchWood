<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Exception\ApplicationAlreadyOpen;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationReference;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\AttachedDocument;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\RequestAnswer;
use Modules\B2B\Domain\ValueObject\RequestKind;
use stdClass;

final readonly class DatabaseApplicationRepository implements ApplicationRepository
{
    private const string TABLE = 'b2b.applications';

    private const string DOCUMENTS = 'b2b.application_documents';

    private const string FLAGS = 'b2b.application_flags';

    private const string REQUESTS = 'b2b.application_requests';

    private const string ANSWERS = 'b2b.application_request_answers';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $applicationId): ?Application
    {
        return $this->one($applicationId, lock: false);
    }

    public function byId(string $applicationId): ?Application
    {
        return $this->one($applicationId, lock: true);
    }

    public function openFor(string $customerId): ?Application
    {
        if (! Ulids::valid($customerId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)
            ->where('customer_id', strtolower($customerId))
            ->whereIn('state', [ApplicationState::Draft->value, ApplicationState::Submitted->value])
            ->first();

        return $row instanceof stdClass ? $this->toApplication($row) : null;
    }

    public function historyOf(string $companyId): array
    {
        if (! Ulids::valid($companyId)) {
            return [];
        }

        $rows = $this->sentBy($companyId)->get();

        return array_values(array_map(fn (stdClass $row): Application => $this->toApplication($row), $rows->all()));
    }

    public function lastSent(string $companyId): ?Application
    {
        if (! Ulids::valid($companyId)) {
            return null;
        }

        $row = $this->sentBy($companyId)->limit(1)->first();

        return $row instanceof stdClass ? $this->toApplication($row) : null;
    }

    public function lockAccount(string $customerId): void
    {
        // A row lock locks nothing while the account has no application or company yet, so two
        // first starts, or two first sends, would both see none. A transaction-scoped advisory lock
        // on the account serialises them (as DatabaseSettings::lockForUpdate does for a setting).
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::accountKey($customerId)], false);
    }

    public function lockAccountForReading(string $customerId): void
    {
        $this->db->select('SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 0))', [self::accountKey($customerId)], false);
    }

    private static function accountKey(string $customerId): string
    {
        return 'b2b:account:'.strtolower($customerId);
    }

    public function add(Application $application): void
    {
        $now = CarbonImmutable::now();

        try {
            $this->db->table(self::TABLE)->insert([
                'id' => $application->id(),
                'customer_id' => $application->customerId(),
                ...self::toRow($application),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // The partial unique index behind the account lock (lesson 64): a violation is not
            // retried, so it is answered here rather than reaching the person as a database error.
            throw str_contains($e->getMessage(), 'applications_one_open_per_customer') ? new ApplicationAlreadyOpen : $e;
        }

        $this->writeHeld($application);
    }

    public function stillHeld(array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $documents = $this->db->table(self::DOCUMENTS)->select('media_id')->whereIn('media_id', $mediaIds);

        return array_values(array_map(
            static fn (mixed $mediaId): string => (string) $mediaId,
            $this->db->table(self::ANSWERS)->select('media_id')->whereIn('media_id', $mediaIds)
                // UNION, not UNION ALL: a file held twice is one file still held.
                ->union($documents)
                ->pluck('media_id')
                ->all(),
        ));
    }

    public function update(Application $application): void
    {
        $this->db->table(self::TABLE)->where('id', $application->id())->update([
            ...self::toRow($application),
            'updated_at' => CarbonImmutable::now(),
        ]);

        $this->writeHeld($application);
    }

    public function delete(string $applicationId): void
    {
        // The document, flag, request and answer rows go by the foreign keys' cascade.
        $this->db->table(self::TABLE)->where('id', strtolower($applicationId))->delete();
    }

    public function accountHolds(string $customerId, string $mediaId): bool
    {
        if (! Ulids::valid($customerId) || ! Ulids::valid($mediaId)) {
            return false;
        }

        $mediaId = strtolower($mediaId);
        $own = $this->db->table(self::TABLE)->select('id')->where('customer_id', strtolower($customerId));

        return $this->db->table(self::DOCUMENTS)->where('media_id', $mediaId)->whereIn('application_id', $own)->exists()
            || $this->db->table(self::ANSWERS)->where('media_id', $mediaId)->whereIn('application_id', clone $own)->exists();
    }

    private function one(string $applicationId, bool $lock): ?Application
    {
        if (! Ulids::valid($applicationId)) {
            return null;
        }

        $query = $this->db->table(self::TABLE)->where('id', strtolower($applicationId));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? $this->toApplication($row) : null;
    }

    /**
     * The company's applications that left DRAFT, newest first.
     */
    private function sentBy(string $companyId): Builder
    {
        return $this->db->table(self::TABLE)
            ->where('company_id', strtolower($companyId))
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');
    }

    /**
     * Everything the application holds besides its own row, each written as a difference from what
     * is stored: a row that is unchanged keeps its id, and one that is gone is deleted.
     */
    private function writeHeld(Application $application): void
    {
        $this->writeDocuments($application);
        $this->writeFlags($application);
        $this->writeRequests($application);
        $this->writeAnswers($application);
    }

    /**
     * The files as the application holds them now. A type whose file is unchanged keeps its row and
     * its id; a replaced or removed one is rewritten or dropped.
     */
    private function writeDocuments(Application $application): void
    {
        $held = $application->documents();

        $stored = $this->db->table(self::DOCUMENTS)
            ->where('application_id', $application->id())
            ->get(['document_type_id', 'media_id'])
            ->mapWithKeys(static fn (stdClass $row): array => [(string) $row->document_type_id => (string) $row->media_id])
            ->all();

        $gone = array_keys(array_filter(
            $stored,
            static fn (string $mediaId, string $typeId): bool => ($held[$typeId]->mediaId ?? null) !== $mediaId,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($gone !== []) {
            $this->db->table(self::DOCUMENTS)->where('application_id', $application->id())->whereIn('document_type_id', $gone)->delete();
        }

        foreach ($held as $typeId => $document) {
            if (($stored[$typeId] ?? null) === $document->mediaId) {
                continue;
            }

            $this->db->table(self::DOCUMENTS)->insert([
                'id' => $this->nextId(),
                'application_id' => $application->id(),
                'document_type_id' => $typeId,
                'media_id' => $document->mediaId,
                'uploaded_at' => CarbonImmutable::instance($document->uploadedAt),
            ]);
        }
    }

    /**
     * The rejection's flags, by what they mark: one row per field or document type.
     */
    private function writeFlags(Application $application): void
    {
        $held = $application->flags();
        $heldKeys = array_map(static fn (ApplicationFlag $flag): string => $flag->key(), $held);

        $stored = [];

        foreach ($this->db->table(self::FLAGS)->where('application_id', $application->id())->get(['id', 'field', 'document_type_id']) as $row) {
            $stored[self::toFlag($row)->key()] = (string) $row->id;
        }

        $gone = array_values(array_diff_key($stored, array_flip($heldKeys)));

        if ($gone !== []) {
            $this->db->table(self::FLAGS)->whereIn('id', $gone)->delete();
        }

        // One per item is the aggregate's rule (Application::reject), which this does not repeat.
        foreach ($held as $flag) {
            if (isset($stored[$flag->key()])) {
                continue;
            }

            $this->db->table(self::FLAGS)->insert([
                'id' => $this->nextId(),
                'application_id' => $application->id(),
                'field' => $flag->field?->value,
                'document_type_id' => $flag->documentTypeId,
            ]);
        }
    }

    /**
     * The rejection's requests, by id. A request never changes once made, so a stored one is kept
     * as it is.
     */
    private function writeRequests(Application $application): void
    {
        $held = [];

        foreach ($application->requests() as $request) {
            $held[$request->id] = $request;
        }

        $stored = $this->db->table(self::REQUESTS)->where('application_id', $application->id())->pluck('id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();

        $gone = array_keys(array_diff_key($stored, $held));

        if ($gone !== []) {
            $this->db->table(self::REQUESTS)->whereIn('id', $gone)->delete();
        }

        foreach (array_diff_key($held, $stored) as $request) {
            $this->db->table(self::REQUESTS)->insert([
                'id' => $request->id,
                'application_id' => $application->id(),
                'kind' => $request->kind->value,
                'label' => $request->label,
                'position' => $request->position,
            ]);
        }
    }

    /**
     * The draft's answers, by request. An unchanged answer keeps its row and its id; a replaced one
     * is rewritten, and a removed one dropped.
     */
    private function writeAnswers(Application $application): void
    {
        $held = $application->answers();

        $stored = [];

        foreach ($this->db->table(self::ANSWERS)->where('application_id', $application->id())->get(['id', 'request_id', 'text', 'media_id']) as $row) {
            $stored[(string) $row->request_id] = $row;
        }

        $gone = [];

        foreach ($stored as $requestId => $row) {
            $answer = $held[$requestId] ?? null;

            if ($answer === null || $answer->text?->value !== ($row->text === null ? null : (string) $row->text) || $answer->mediaId !== ($row->media_id === null ? null : (string) $row->media_id)) {
                $gone[$requestId] = (string) $row->id;
            }
        }

        if ($gone !== []) {
            $this->db->table(self::ANSWERS)->whereIn('id', array_values($gone))->delete();
        }

        foreach ($held as $requestId => $answer) {
            if (isset($stored[$requestId]) && ! isset($gone[$requestId])) {
                continue;
            }

            $this->db->table(self::ANSWERS)->insert([
                'id' => $this->nextId(),
                'application_id' => $application->id(),
                'request_id' => $requestId,
                'text' => $answer->text?->value,
                'media_id' => $answer->mediaId,
            ]);
        }
    }

    /**
     * @return array<string, string|CarbonImmutable|null>
     */
    private static function toRow(Application $application): array
    {
        return [
            'company_id' => $application->companyId(),
            'state' => $application->state()->value,
            'name' => $application->name()?->value,
            'company_type_id' => $application->type()?->typeId,
            'company_type_other' => $application->type()?->other,
            'cr_number' => $application->crNumber()?->value,
            'tax_number' => $application->taxNumber()?->value,
            'address' => $application->address()?->value,
            'address_id' => $application->address()?->addressId,
            'note' => $application->note()?->value,
            'submitted_at' => self::time($application->submittedAt()),
            'reference' => $application->reference()?->value,
            'decided_at' => self::time($application->decidedAt()),
            'decided_by' => $application->decidedBy(),
            'decision_reason' => $application->decisionReason()?->value,
        ];
    }

    private function toApplication(stdClass $row): Application
    {
        $documents = [];

        foreach ($this->db->table(self::DOCUMENTS)->where('application_id', $row->id)->orderBy('document_type_id')->get() as $document) {
            $typeId = (string) $document->document_type_id;
            $documents[$typeId] = new AttachedDocument($typeId, (string) $document->media_id, CarbonImmutable::parse((string) $document->uploaded_at));
        }

        $flags = [];

        foreach ($this->db->table(self::FLAGS)->where('application_id', $row->id)->orderBy('id')->get() as $flag) {
            $flags[] = self::toFlag($flag);
        }

        $requests = [];

        foreach ($this->db->table(self::REQUESTS)->where('application_id', $row->id)->orderBy('position')->orderBy('id')->get() as $request) {
            $requests[] = ApplicationRequest::reconstitute((string) $request->id, RequestKind::from((string) $request->kind), (string) $request->label, (int) $request->position);
        }

        $answers = [];

        foreach ($this->db->table(self::ANSWERS)->where('application_id', $row->id)->orderBy('request_id')->get() as $answer) {
            $answers[] = $answer->media_id === null
                ? RequestAnswer::text((string) $answer->request_id, Remark::reconstitute((string) $answer->text))
                : RequestAnswer::file((string) $answer->request_id, (string) $answer->media_id);
        }

        $typeId = $row->company_type_id === null ? null : (string) $row->company_type_id;
        $other = $row->company_type_other === null ? null : (string) $row->company_type_other;

        return Application::reconstitute(
            (string) $row->id,
            (string) $row->customer_id,
            $row->company_id === null ? null : (string) $row->company_id,
            ApplicationState::from((string) $row->state),
            $row->name === null ? null : CompanyName::reconstitute((string) $row->name),
            $typeId === null && $other === null ? null : CompanyTypeChoice::reconstitute($typeId, $other),
            $row->cr_number === null ? null : RegistrationNumber::reconstitute((string) $row->cr_number),
            $row->tax_number === null ? null : RegistrationNumber::reconstitute((string) $row->tax_number),
            $row->address === null ? null : CompanyAddress::reconstitute((string) $row->address, $row->address_id === null ? null : (string) $row->address_id),
            $row->note === null ? null : Remark::reconstitute((string) $row->note),
            $documents,
            $row->submitted_at === null ? null : CarbonImmutable::parse((string) $row->submitted_at),
            $row->reference === null ? null : ApplicationReference::reconstitute((string) $row->reference),
            $row->decided_at === null ? null : CarbonImmutable::parse((string) $row->decided_at),
            $row->decided_by === null ? null : (string) $row->decided_by,
            $row->decision_reason === null ? null : Remark::reconstitute((string) $row->decision_reason),
            $flags,
            $requests,
            $answers,
        );
    }

    private static function toFlag(stdClass $row): ApplicationFlag
    {
        // Exactly one of the two, by the table's CHECK.
        return $row->field !== null
            ? ApplicationFlag::field(FlaggedField::from((string) $row->field))
            : ApplicationFlag::document((string) $row->document_type_id);
    }

    private static function time(?DateTimeImmutable $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::instance($at);
    }
}

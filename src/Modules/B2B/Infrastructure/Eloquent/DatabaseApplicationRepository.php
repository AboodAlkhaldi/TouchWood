<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\AttachedDocument;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use stdClass;

final readonly class DatabaseApplicationRepository implements ApplicationRepository
{
    private const string TABLE = 'b2b.applications';

    private const string DOCUMENTS = 'b2b.application_documents';

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

        $rows = $this->db->table(self::TABLE)
            ->where('company_id', strtolower($companyId))
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        return array_values(array_map(fn (stdClass $row): Application => $this->toApplication($row), $rows->all()));
    }

    public function add(Application $application): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TABLE)->insert([
            'id' => $application->id(),
            'customer_id' => $application->customerId(),
            ...self::toRow($application),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->writeDocuments($application);
    }

    public function update(Application $application): void
    {
        $this->db->table(self::TABLE)->where('id', $application->id())->update([
            ...self::toRow($application),
            'updated_at' => CarbonImmutable::now(),
        ]);

        $this->writeDocuments($application);
    }

    public function delete(string $applicationId): void
    {
        // The document rows go by the foreign key's cascade.
        $this->db->table(self::TABLE)->where('id', strtolower($applicationId))->delete();
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
            'note' => $application->note()?->value,
            'submitted_at' => self::time($application->submittedAt()),
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
            $row->address === null ? null : CompanyAddress::reconstitute((string) $row->address),
            $row->note === null ? null : Remark::reconstitute((string) $row->note),
            $documents,
            $row->submitted_at === null ? null : CarbonImmutable::parse((string) $row->submitted_at),
            $row->decided_at === null ? null : CarbonImmutable::parse((string) $row->decided_at),
            $row->decided_by === null ? null : (string) $row->decided_by,
            $row->decision_reason === null ? null : Remark::reconstitute((string) $row->decision_reason),
        );
    }

    private static function time(?DateTimeImmutable $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::instance($at);
    }
}

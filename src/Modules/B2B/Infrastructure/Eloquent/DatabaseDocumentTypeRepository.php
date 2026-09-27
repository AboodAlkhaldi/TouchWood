<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\ValueObject\TypeName;
use stdClass;

final readonly class DatabaseDocumentTypeRepository implements DocumentTypeRepository
{
    private const string TABLE = 'b2b.document_types';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $typeId): ?DocumentType
    {
        if (! Ulids::valid($typeId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($typeId))->first();

        return $row instanceof stdClass ? self::toType($row) : null;
    }

    public function byId(string $typeId): ?DocumentType
    {
        if (! Ulids::valid($typeId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($typeId))->lockForUpdate()->first();

        return $row instanceof stdClass ? self::toType($row) : null;
    }

    public function all(): array
    {
        return $this->list($this->db->table(self::TABLE));
    }

    public function active(): array
    {
        return $this->list($this->db->table(self::TABLE)->where('is_active', true));
    }

    public function nameTaken(TypeName $name, ?string $exceptId = null): bool
    {
        return TypeNames::taken($this->db->table(self::TABLE), $name, $exceptId);
    }

    public function add(DocumentType $type): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TABLE)->insert([
            'id' => $type->id(),
            ...self::toRow($type),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(DocumentType $type): void
    {
        $this->db->table(self::TABLE)->where('id', $type->id())->update([
            ...self::toRow($type),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @return list<DocumentType>
     */
    private function list(Builder $query): array
    {
        $rows = $query->orderBy('position')->orderBy('name_en')->orderBy('id')->get();

        return array_values(array_map(static fn (stdClass $row): DocumentType => self::toType($row), $rows->all()));
    }

    /**
     * @return array<string, string|int|bool>
     */
    private static function toRow(DocumentType $type): array
    {
        return [
            'name_ar' => $type->name()->ar,
            'name_en' => $type->name()->en,
            'position' => $type->position(),
            'is_active' => $type->isActive(),
            'is_required' => $type->isRequired(),
        ];
    }

    private static function toType(stdClass $row): DocumentType
    {
        return DocumentType::reconstitute(
            (string) $row->id,
            TypeName::reconstitute((string) $row->name_ar, (string) $row->name_en),
            (int) $row->position,
            (bool) $row->is_active,
            (bool) $row->is_required,
        );
    }
}

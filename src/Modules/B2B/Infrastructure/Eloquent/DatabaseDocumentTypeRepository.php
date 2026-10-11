<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\TypeName;
use Shared\Infrastructure\Persistence\Ulids;
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

    public function all(string $storeId): array
    {
        if (! Ulids::valid($storeId)) {
            return [];
        }

        return $this->list($this->db->table(self::TABLE)->where('store_id', strtolower($storeId)));
    }

    public function active(string $storeId): array
    {
        if (! Ulids::valid($storeId)) {
            return [];
        }

        return $this->list($this->db->table(self::TABLE)->where('store_id', strtolower($storeId))->where('is_active', true));
    }

    public function nameTaken(string $storeId, TypeName $name, ?string $exceptId = null): bool
    {
        return TypeNames::taken($this->db->table(self::TABLE), $storeId, $name, $exceptId);
    }

    public function add(DocumentType $type): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TABLE)->insert([
            'id' => $type->id(),
            'store_id' => $type->storeId(),
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
     * @return array<string, string|int|bool|null>
     */
    private static function toRow(DocumentType $type): array
    {
        return [
            'name_ar' => $type->name()->ar,
            'name_en' => $type->name()->en,
            'position' => $type->position(),
            'is_active' => $type->isActive(),
            'inactive_display' => $type->inactiveDisplay()?->value,
            'is_required' => $type->isRequired(),
        ];
    }

    private static function toType(stdClass $row): DocumentType
    {
        return DocumentType::reconstitute(
            (string) $row->id,
            (string) $row->store_id,
            TypeName::reconstitute((string) $row->name_ar, (string) $row->name_en),
            (int) $row->position,
            (bool) $row->is_active,
            $row->inactive_display === null ? null : InactiveTypeDisplay::from((string) $row->inactive_display),
            (bool) $row->is_required,
        );
    }
}

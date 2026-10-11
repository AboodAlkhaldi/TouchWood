<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\Attribute;
use Modules\Catalog\Domain\Model\AttributeValue;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Public\Enums\AttributeKind;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

final readonly class DatabaseAttributeRepository implements AttributeRepository
{
    private const string ATTRIBUTES = 'catalog.attributes';

    private const string VALUES = 'catalog.attribute_values';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $attributeId): ?Attribute
    {
        $row = $this->row(self::ATTRIBUTES, $attributeId, false);

        return $row === null ? null : self::toAttribute($row);
    }

    public function byId(string $attributeId): ?Attribute
    {
        $row = $this->row(self::ATTRIBUTES, $attributeId, true);

        return $row === null ? null : self::toAttribute($row);
    }

    public function add(Attribute $attribute): void
    {
        $now = CarbonImmutable::now();
        $this->db->table(self::ATTRIBUTES)->insert(['id' => $attribute->id(), ...self::attributeRow($attribute), 'created_at' => $now, 'updated_at' => $now]);
    }

    public function update(Attribute $attribute): void
    {
        $this->db->table(self::ATTRIBUTES)->where('id', $attribute->id())->update([...self::attributeRow($attribute), 'updated_at' => CarbonImmutable::now()]);
    }

    public function delete(string $attributeId): void
    {
        $this->db->table(self::ATTRIBUTES)->where('id', strtolower($attributeId))->delete();
    }

    public function all(): array
    {
        return array_values(array_map(
            static fn (stdClass $row): Attribute => self::toAttribute($row),
            $this->db->table(self::ATTRIBUTES)->orderBy('position')->orderBy('name_en')->orderBy('id')->get()->all(),
        ));
    }

    public function hasValues(string $attributeId): bool
    {
        return $this->db->table(self::VALUES)->where('attribute_id', strtolower($attributeId))->exists();
    }

    public function makesVariants(string $attributeId): bool
    {
        return $this->db->table('catalog.product_attributes')->where('attribute_id', strtolower($attributeId))->exists();
    }

    public function findValue(string $valueId): ?AttributeValue
    {
        $row = $this->row(self::VALUES, $valueId, false);

        return $row === null ? null : self::toValue($row);
    }

    public function valueById(string $valueId): ?AttributeValue
    {
        $row = $this->row(self::VALUES, $valueId, true);

        return $row === null ? null : self::toValue($row);
    }

    public function valueNameTaken(string $attributeId, LocalizedName $name, ?string $exceptValueId = null): bool
    {
        return $this->db->table(self::VALUES)
            ->where('attribute_id', strtolower($attributeId))
            ->when($exceptValueId !== null, fn ($query) => $query->where('id', '<>', strtolower((string) $exceptValueId)))
            ->where(fn ($query) => $query
                ->whereRaw('lower(name_ar) = lower(?)', [$name->ar])
                ->orWhereRaw('lower(name_en) = lower(?)', [$name->en]))
            ->exists();
    }

    public function addValue(AttributeValue $value): void
    {
        $now = CarbonImmutable::now();
        $this->db->table(self::VALUES)->insert(['id' => $value->id(), 'attribute_id' => $value->attributeId(), ...self::valueRow($value), 'created_at' => $now, 'updated_at' => $now]);
    }

    public function updateValue(AttributeValue $value): void
    {
        $this->db->table(self::VALUES)->where('id', $value->id())->update([...self::valueRow($value), 'updated_at' => CarbonImmutable::now()]);
    }

    public function deleteValue(string $valueId): void
    {
        $this->db->table(self::VALUES)->where('id', strtolower($valueId))->delete();
    }

    public function valuesOf(string $attributeId): array
    {
        if (! Ulids::valid($attributeId)) {
            return [];
        }

        return array_values(array_map(
            static fn (stdClass $row): AttributeValue => self::toValue($row),
            $this->db->table(self::VALUES)->where('attribute_id', strtolower($attributeId))->orderBy('position')->orderBy('name_en')->orderBy('id')->get()->all(),
        ));
    }

    private function row(string $table, string $id, bool $lock): ?stdClass
    {
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table($table)->where('id', strtolower($id))->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row instanceof stdClass ? $row : null;
    }

    private static function toAttribute(stdClass $row): Attribute
    {
        return Attribute::reconstitute(
            (string) $row->id,
            LocalizedName::reconstitute((string) $row->name_ar, (string) $row->name_en),
            AttributeKind::from((string) $row->kind),
            $row->unit_ar === null ? null : (string) $row->unit_ar,
            $row->unit_en === null ? null : (string) $row->unit_en,
            (bool) $row->is_colour,
            (int) $row->position,
            (bool) $row->is_active,
        );
    }

    private static function toValue(stdClass $row): AttributeValue
    {
        return AttributeValue::reconstitute(
            (string) $row->id,
            (string) $row->attribute_id,
            LocalizedName::reconstitute((string) $row->name_ar, (string) $row->name_en),
            $row->swatch === null ? null : (string) $row->swatch,
            (int) $row->position,
            (bool) $row->is_active,
        );
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private static function attributeRow(Attribute $attribute): array
    {
        return [
            'name_ar' => $attribute->name()->ar,
            'name_en' => $attribute->name()->en,
            'kind' => $attribute->kind()->value,
            'unit_ar' => $attribute->unitAr(),
            'unit_en' => $attribute->unitEn(),
            'is_colour' => $attribute->isColour(),
            'position' => $attribute->position(),
            'is_active' => $attribute->isActive(),
        ];
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private static function valueRow(AttributeValue $value): array
    {
        return [
            'name_ar' => $value->name()->ar,
            'name_en' => $value->name()->en,
            'swatch' => $value->swatch(),
            'position' => $value->position(),
            'is_active' => $value->isActive(),
        ];
    }
}

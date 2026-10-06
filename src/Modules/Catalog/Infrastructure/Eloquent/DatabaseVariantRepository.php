<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\Combination;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Domain\ValueObject\VariantDetail;
use Modules\Catalog\Domain\ValueObject\VariantMeasures;
use stdClass;

final readonly class DatabaseVariantRepository implements VariantRepository
{
    private const string TABLE = 'catalog.variants';

    private const string VALUES = 'catalog.variant_values';

    private const string DETAILS = 'catalog.variant_details';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $variantId): ?Variant
    {
        return $this->read($variantId, false);
    }

    public function byId(string $variantId): ?Variant
    {
        return $this->read($variantId, true);
    }

    public function ofProduct(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        return $this->toVariants($this->db->table(self::TABLE)->where('product_id', strtolower($productId))->orderBy('position')->orderBy('id')->get()->all());
    }

    public function hasAny(string $productId): bool
    {
        return Ulids::valid($productId) && $this->db->table(self::TABLE)->where('product_id', strtolower($productId))->exists();
    }

    public function add(Variant $variant): void
    {
        $now = CarbonImmutable::now();
        $this->db->table(self::TABLE)->insert(['id' => $variant->id(), 'product_id' => $variant->productId(), ...self::toRow($variant), 'created_at' => $now, 'updated_at' => $now]);
        $this->writeValuesAndDetails($variant);
    }

    public function update(Variant $variant): void
    {
        $this->updateRow($variant);
        $this->db->table(self::VALUES)->where('variant_id', $variant->id())->delete();
        $this->db->table(self::DETAILS)->where('variant_id', $variant->id())->delete();
        $this->writeValuesAndDetails($variant);
    }

    public function updateRow(Variant $variant): void
    {
        $this->db->table(self::TABLE)->where('id', $variant->id())->update([...self::toRow($variant), 'updated_at' => CarbonImmutable::now()]);
    }

    public function delete(string $variantId): void
    {
        $this->db->table(self::TABLE)->where('id', strtolower($variantId))->delete();
    }

    public function combinationTaken(string $productId, string $combination, ?string $exceptVariantId = null): bool
    {
        return $this->db->table(self::TABLE)
            ->where('product_id', strtolower($productId))
            ->where('combination', $combination)
            ->when($exceptVariantId !== null, fn ($query) => $query->where('id', '<>', strtolower((string) $exceptVariantId)))
            ->exists();
    }

    public function codeInUse(string $productId, string $code, ?string $exceptVariantId = null): bool
    {
        return $this->db->table(self::TABLE)
            ->where('product_id', strtolower($productId))
            ->where('code', $code)
            ->when($exceptVariantId !== null, fn ($query) => $query->where('id', '<>', strtolower((string) $exceptVariantId)))
            ->exists();
    }

    public function renameCode(string $productId, string $from, string $to): void
    {
        $this->db->table(self::TABLE)->where('product_id', strtolower($productId))->where('code', $from)->update(['code' => $to, 'updated_at' => CarbonImmutable::now()]);
    }

    public function anyWithValue(string $valueId): bool
    {
        return Ulids::valid($valueId) && $this->db->table(self::VALUES)->where('value_id', strtolower($valueId))->exists();
    }

    public function anyWithAttribute(string $attributeId): bool
    {
        if (! Ulids::valid($attributeId)) {
            return false;
        }

        $attributeId = strtolower($attributeId);

        return $this->db->table(self::VALUES)->where('attribute_id', $attributeId)->exists()
            || $this->db->table(self::DETAILS)->where('attribute_id', $attributeId)->exists();
    }

    public function photos(string $variantId): array
    {
        if (! Ulids::valid($variantId)) {
            return [];
        }

        return array_values(array_map('strval', $this->db->table('catalog.variant_photos')->where('variant_id', strtolower($variantId))->orderBy('position')->pluck('media_id')->all()));
    }

    public function replacePhotos(string $variantId, array $mediaIds): void
    {
        // A photo that stays is moved, never written again: a new row would take a key lock on its
        // media row, which deleting that file holds while it waits for the products' lock.
        $this->db->table('catalog.variant_photos')->where('variant_id', $variantId)->whereNotIn('media_id', $mediaIds)->delete();
        $held = $this->db->table('catalog.variant_photos')->where('variant_id', $variantId)->pluck('position', 'media_id')->all();

        foreach ($mediaIds as $position => $mediaId) {
            if (! array_key_exists($mediaId, $held)) {
                $this->db->table('catalog.variant_photos')->insert(['variant_id' => $variantId, 'media_id' => $mediaId, 'position' => $position]);
            } elseif ((int) $held[$mediaId] !== $position) {
                $this->db->table('catalog.variant_photos')->where('variant_id', $variantId)->where('media_id', $mediaId)->update(['position' => $position]);
            }
        }
    }

    public function withPhoto(string $mediaId): array
    {
        return array_values(array_map('strval', $this->db->table('catalog.variant_photos')->where('media_id', strtolower($mediaId))->orderBy('variant_id')->pluck('variant_id')->all()));
    }

    public function removePhoto(string $variantId, string $mediaId): void
    {
        $this->db->table('catalog.variant_photos')->where('variant_id', $variantId)->where('media_id', strtolower($mediaId))->delete();
    }

    private function read(string $variantId, bool $lock): ?Variant
    {
        if (! Ulids::valid($variantId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($variantId))->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row instanceof stdClass ? $this->toVariants([$row])[0] : null;
    }

    private function writeValuesAndDetails(Variant $variant): void
    {
        $values = [];

        foreach ($variant->combination()->valueIds as $attributeId => $valueId) {
            $values[] = ['variant_id' => $variant->id(), 'attribute_id' => $attributeId, 'value_id' => $valueId];
        }

        if ($values !== []) {
            $this->db->table(self::VALUES)->insert($values);
        }

        $details = [];

        foreach ($variant->details() as $attributeId => $detail) {
            $details[] = ['variant_id' => $variant->id(), 'attribute_id' => $attributeId, 'text_ar' => $detail->textAr, 'text_en' => $detail->textEn, 'number' => $detail->number];
        }

        if ($details !== []) {
            $this->db->table(self::DETAILS)->insert($details);
        }
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<Variant>
     */
    private function toVariants(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));
        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $attributeOf = [];
        $details = [];

        if ($ids !== []) {
            foreach ($this->db->table(self::VALUES)->whereIn('variant_id', $ids)->get() as $value) {
                $attributeOf[(string) $value->variant_id][(string) $value->value_id] = (string) $value->attribute_id;
            }

            foreach ($this->db->table(self::DETAILS)->whereIn('variant_id', $ids)->get() as $detail) {
                $details[(string) $detail->variant_id][(string) $detail->attribute_id] = VariantDetail::reconstitute(
                    $detail->text_ar === null ? null : (string) $detail->text_ar,
                    $detail->text_en === null ? null : (string) $detail->text_en,
                    $detail->number === null ? null : (string) $detail->number,
                );
            }
        }

        return array_map(function (stdClass $row) use ($attributeOf, $details): Variant {
            $id = (string) $row->id;
            $valueIds = [];

            // The combination keeps the set's order; the value rows say each value's attribute.
            foreach ((string) $row->combination === '' ? [] : explode(',', (string) $row->combination) as $valueId) {
                $valueIds[$attributeOf[$id][$valueId] ?? ''] = $valueId;
            }

            return Variant::reconstitute(
                $id,
                (string) $row->product_id,
                ProductCode::reconstitute((string) $row->code),
                Combination::reconstitute($valueIds),
                $details[$id] ?? [],
                VariantMeasures::reconstitute(
                    $row->weight_grams === null ? null : (int) $row->weight_grams,
                    $row->length_mm === null ? null : (int) $row->length_mm,
                    $row->width_mm === null ? null : (int) $row->width_mm,
                    $row->height_mm === null ? null : (int) $row->height_mm,
                ),
                (bool) $row->is_archived,
                (int) $row->position,
            );
        }, $rows);
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private static function toRow(Variant $variant): array
    {
        return [
            'code' => $variant->code()->value,
            'combination' => $variant->combination()->key(),
            'weight_grams' => $variant->measures()->weightGrams,
            'length_mm' => $variant->measures()->lengthMm,
            'width_mm' => $variant->measures()->widthMm,
            'height_mm' => $variant->measures()->heightMm,
            'is_archived' => $variant->isArchived(),
            'position' => $variant->position(),
        ];
    }
}

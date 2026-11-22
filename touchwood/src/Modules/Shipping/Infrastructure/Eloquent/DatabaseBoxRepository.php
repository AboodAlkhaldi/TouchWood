<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Shipping\Domain\Model\Box;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

final readonly class DatabaseBoxRepository implements BoxRepository
{
    private const string TABLE = 'shipping.boxes';

    private const string PACKING = 'shipping.variant_packing';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function lockStore(string $storeId): void
    {
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['shipping:boxes:'.strtolower($storeId)], false);
    }

    public function byId(string $storeId, string $boxId): ?Box
    {
        $id = strtolower(trim($boxId));

        // A malformed id is no box: never sent to a ulid column.
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', $id)->where('store_id', strtolower($storeId))->first();

        return $row === null ? null : $this->box($row);
    }

    public function inStore(string $storeId): array
    {
        $boxes = [];

        foreach ($this->db->table(self::TABLE)->where('store_id', strtolower($storeId))->orderBy('position')->orderBy('id')->get() as $row) {
            $boxes[] = $this->box($row);
        }

        return $boxes;
    }

    public function named(string $storeId, string $name, ?string $exceptId): ?Box
    {
        $query = $this->db->table(self::TABLE)
            ->where('store_id', strtolower($storeId))
            ->whereRaw('lower(name) = lower(?)', [trim($name)]);

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        $row = $query->first();

        return $row === null ? null : $this->box($row);
    }

    public function add(Box $box): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TABLE)->insert([
            'id' => $box->id,
            'store_id' => $box->storeId,
            ...$this->columns($box),
            'position' => $box->position,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Box $box): void
    {
        $this->db->table(self::TABLE)->where('id', $box->id)->update([...$this->columns($box), 'updated_at' => CarbonImmutable::now()]);
    }

    public function delete(string $boxId): void
    {
        $this->db->table(self::TABLE)->where('id', $boxId)->delete();
    }

    public function writePositions(array $positions): void
    {
        foreach ($positions as $id => $position) {
            $this->db->table(self::TABLE)->where('id', $id)->update(['position' => $position]);
        }
    }

    public function handPacked(string $storeId, array $variantIds): array
    {
        $ids = array_values(array_filter(array_map(strtolower(...), $variantIds), Ulids::valid(...)));

        if ($ids === []) {
            return [];
        }

        $packed = [];

        foreach ($this->db->table(self::PACKING)->where('store_id', strtolower($storeId))->whereIn('variant_id', $ids)->pluck('variant_id') as $id) {
            $packed[] = (string) $id;
        }

        return $packed;
    }

    public function allHandPacked(string $storeId): array
    {
        $packed = [];

        foreach ($this->db->table(self::PACKING)->where('store_id', strtolower($storeId))->orderBy('variant_id')->pluck('variant_id') as $id) {
            $packed[] = (string) $id;
        }

        return $packed;
    }

    public function setHandPacked(string $storeId, string $variantId, bool $byHand): bool
    {
        $key = ['store_id' => strtolower($storeId), 'variant_id' => strtolower($variantId)];

        if ($byHand) {
            return $this->db->table(self::PACKING)->insertOrIgnore([...$key, 'created_at' => CarbonImmutable::now()]) > 0;
        }

        return $this->db->table(self::PACKING)->where($key)->delete() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(Box $box): array
    {
        [$length, $width, $height] = $box->sizes();

        return [
            'name' => $box->name(),
            'length_mm' => $length,
            'width_mm' => $width,
            'height_mm' => $height,
            'max_weight_g' => $box->maxWeightG(),
            'active' => $box->isActive(),
        ];
    }

    private function box(stdClass $row): Box
    {
        return Box::reconstitute(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->name,
            (int) $row->length_mm,
            (int) $row->width_mm,
            (int) $row->height_mm,
            (int) $row->max_weight_g,
            (bool) $row->active,
            (int) $row->position,
        );
    }
}

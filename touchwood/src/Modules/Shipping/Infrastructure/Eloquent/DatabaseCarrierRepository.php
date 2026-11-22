<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Shipping\Domain\Model\Carrier;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Domain\ValueObject\CarrierDetails;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

final readonly class DatabaseCarrierRepository implements CarrierRepository
{
    private const string TABLE = 'shipping.carriers';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function lockStore(string $storeId): void
    {
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['shipping:carriers:'.strtolower($storeId)], false);
    }

    public function byId(string $storeId, string $carrierId): ?Carrier
    {
        $id = strtolower(trim($carrierId));

        // A malformed id is no carrier: never sent to a ulid column.
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', $id)->where('store_id', strtolower($storeId))->first();

        return $row === null ? null : $this->carrier($row);
    }

    public function find(string $carrierId): ?Carrier
    {
        $id = strtolower(trim($carrierId));

        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', $id)->first();

        return $row === null ? null : $this->carrier($row);
    }

    public function inStore(string $storeId): array
    {
        return array_values(array_map(
            $this->carrier(...),
            $this->db->table(self::TABLE)->where('store_id', strtolower($storeId))->orderBy('country_code')->orderBy('position')->orderBy('id')->get()->all(),
        ));
    }

    public function add(Carrier $carrier): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TABLE)->insert([
            'id' => $carrier->id,
            'store_id' => $carrier->storeId,
            'country_code' => $carrier->countryCode,
            ...$this->columns($carrier),
            'position' => $carrier->position,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Carrier $carrier): void
    {
        $this->db->table(self::TABLE)->where('id', $carrier->id)->update([
            ...$this->columns($carrier),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function delete(string $carrierId): void
    {
        $this->db->table(self::TABLE)->where('id', $carrierId)->delete();
    }

    public function named(string $storeId, string $language, string $name, ?string $exceptId): ?Carrier
    {
        $column = $language === 'ar' ? 'name_ar' : 'name_en';
        $query = $this->db->table(self::TABLE)
            ->where('store_id', strtolower($storeId))
            ->whereRaw("lower({$column}) = lower(?)", [trim($name)]);

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        $row = $query->first();

        return $row === null ? null : $this->carrier($row);
    }

    public function positions(string $storeId, string $countryCode): array
    {
        $positions = [];

        foreach ($this->db->table(self::TABLE)->where('store_id', strtolower($storeId))->where('country_code', $countryCode)->orderBy('position')->orderBy('id')->get(['id', 'position']) as $row) {
            $positions[(string) $row->id] = (int) $row->position;
        }

        return $positions;
    }

    public function writePositions(array $positions): void
    {
        if ($positions === []) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($positions as $id => $position) {
            $cases[] = 'WHEN ? THEN ?::integer';
            $bindings[] = $id;
            $bindings[] = $position;
        }

        $ids = array_keys($positions);
        $this->db->update(
            'UPDATE shipping.carriers SET position = CASE id '.implode(' ', $cases).' END, updated_at = now() WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).')',
            [...$bindings, ...$ids],
        );
    }

    public function countIn(string $storeId, string $countryCode): int
    {
        return $this->db->table(self::TABLE)->where('store_id', strtolower($storeId))->where('country_code', $countryCode)->count();
    }

    public function withLogo(string $mediaId): array
    {
        return array_values(array_map(
            $this->carrier(...),
            $this->db->table(self::TABLE)->where('logo_media_id', strtolower($mediaId))->orderBy('id')->get()->all(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(Carrier $carrier): array
    {
        $details = $carrier->details();

        return [
            'name_ar' => $details->nameAr,
            'name_en' => $details->nameEn,
            'logo_media_id' => $carrier->logoMediaId(),
            'phone' => $details->phone,
            'website' => $details->website,
            'tracking_pattern' => $details->trackingPattern,
            'free_above_minor' => $carrier->freeAboveMinor(),
            'active' => $carrier->isActive(),
        ];
    }

    private function carrier(stdClass $row): Carrier
    {
        return Carrier::reconstitute(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->country_code,
            CarrierDetails::reconstitute((string) $row->name_ar, (string) $row->name_en, $row->phone === null ? null : (string) $row->phone, $row->website === null ? null : (string) $row->website, $row->tracking_pattern === null ? null : (string) $row->tracking_pattern),
            $row->logo_media_id === null ? null : (string) $row->logo_media_id,
            $row->free_above_minor === null ? null : (int) $row->free_above_minor,
            (bool) $row->active,
            (int) $row->position,
        );
    }
}

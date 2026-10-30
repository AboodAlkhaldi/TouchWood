<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Access\Domain\Model\Address;
use Modules\Access\Domain\Model\AddressPlaces;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Domain\ValueObject\MapPin;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

final readonly class DatabaseAddressRepository implements AddressRepository
{
    private const string TABLE = 'access.addresses';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $addressId): ?Address
    {
        if (! Ulids::valid($addressId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($addressId))->first();

        return $row instanceof stdClass ? $this->toAddress($row) : null;
    }

    public function byId(string $addressId): ?Address
    {
        if (! Ulids::valid($addressId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($addressId))->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toAddress($row) : null;
    }

    public function forCustomerInStore(string $customerId, string $storeId): array
    {
        if (! Ulids::valid($customerId) || ! Ulids::valid($storeId)) {
            return [];
        }

        $rows = $this->db->table(self::TABLE)
            ->where('customer_id', strtolower($customerId))
            ->where('store_id', strtolower($storeId))
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return array_values(array_map(fn (stdClass $row): Address => $this->toAddress($row), $rows->all()));
    }

    public function forCustomer(string $customerId): array
    {
        if (! Ulids::valid($customerId)) {
            return [];
        }

        $rows = $this->db->table(self::TABLE)
            ->where('customer_id', strtolower($customerId))
            ->orderBy('store_id')
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return array_values(array_map(fn (stdClass $row): Address => $this->toAddress($row), $rows->all()));
    }

    public function countForCustomerInStore(string $customerId, string $storeId): int
    {
        return $this->db->table(self::TABLE)
            ->where('customer_id', strtolower($customerId))
            ->where('store_id', strtolower($storeId))
            ->count();
    }

    public function newestInStore(string $customerId, string $storeId, string $exceptId): ?Address
    {
        $row = $this->db->table(self::TABLE)
            ->where('customer_id', strtolower($customerId))
            ->where('store_id', strtolower($storeId))
            ->where('id', '!=', strtolower($exceptId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->toAddress($row) : null;
    }

    public function add(Address $address): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TABLE)->insert([
            'id' => $address->id(),
            'customer_id' => strtolower($address->customerId()),
            'store_id' => strtolower($address->storeId()),
            ...$this->attributes($address),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Address $address): void
    {
        $this->db->table(self::TABLE)->where('id', $address->id())->update([
            ...$this->attributes($address),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function delete(string $addressId): void
    {
        $this->db->table(self::TABLE)->where('id', strtolower($addressId))->delete();
    }

    public function deleteForCustomer(string $customerId): void
    {
        if (! Ulids::valid($customerId)) {
            return;
        }

        $this->db->table(self::TABLE)->where('customer_id', strtolower($customerId))->delete();
    }

    public function clearDefault(string $customerId, string $storeId, string $exceptId): void
    {
        $this->db->table(self::TABLE)
            ->where('customer_id', strtolower($customerId))
            ->where('store_id', strtolower($storeId))
            ->where('id', '!=', strtolower($exceptId))
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => CarbonImmutable::now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Address $address): array
    {
        return [
            'label' => $address->label(),
            'recipient_name' => $address->recipientName(),
            // As an object, always: a format whose fields are all optional may leave none, and the
            // column refuses a JSON array (review of step 5).
            'fields' => json_encode((object) $address->fields(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'latitude' => $address->pin()?->latitude,
            'longitude' => $address->pin()?->longitude,
            'is_default' => $address->isDefault(),
            'country_code' => $address->places()->countryCode,
            'region_id' => $address->places()->regionId,
            'city_id' => $address->places()->cityId,
            'district_id' => $address->places()->districtId,
            'language' => $address->places()->language,
        ];
    }

    private function toAddress(stdClass $row): Address
    {
        $fields = json_decode((string) $row->fields, true, 512, JSON_THROW_ON_ERROR);

        return Address::reconstitute(
            (string) $row->id,
            (string) $row->customer_id,
            (string) $row->store_id,
            (string) $row->label,
            (string) $row->recipient_name,
            // Only the values this module writes: a row edited by hand cannot make a read throw.
            is_array($fields) ? array_filter($fields, is_string(...)) : [],
            MapPin::optional(
                $row->latitude === null ? null : (float) $row->latitude,
                $row->longitude === null ? null : (float) $row->longitude,
            ),
            (bool) $row->is_default,
            AddressPlaces::reconstitute(
                (string) $row->country_code,
                $row->region_id === null ? null : (string) $row->region_id,
                $row->city_id === null ? null : (string) $row->city_id,
                $row->district_id === null ? null : (string) $row->district_id,
                (string) $row->language,
            ),
        );
    }
}

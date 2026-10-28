<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Domain\Model\AddressCountry;
use Modules\Platform\Domain\Repository\AddressCountryRepository;

final readonly class DatabaseAddressCountries implements AddressCountryRepository
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function byCode(string $code): ?AddressCountry
    {
        $row = $this->db->table('platform.address_countries')->where('country_code', $code)->lockForUpdate()->first(['country_code', 'retired']);

        return $row === null ? null : AddressCountry::reconstitute((string) $row->country_code, (bool) $row->retired);
    }

    public function add(AddressCountry $country): bool
    {
        // ON CONFLICT DO NOTHING: a second add at the same moment waits for the first, then adds nothing.
        return $this->db->table('platform.address_countries')->insertOrIgnore([
            'country_code' => $country->code,
            'retired' => $country->isRetired(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    public function update(AddressCountry $country): void
    {
        $this->db->table('platform.address_countries')->where('country_code', $country->code)->update([
            'retired' => $country->isRetired(),
            'updated_at' => now(),
        ]);
    }

    public function hasLiveCity(string $code): bool
    {
        return $this->db->table('platform.places as city')
            ->join('platform.places as region', 'region.id', '=', 'city.parent_id')
            ->where('city.country_code', $code)
            ->where('city.kind', 'CITY')
            ->where('city.retired', false)
            ->where('region.retired', false)
            ->exists();
    }
}

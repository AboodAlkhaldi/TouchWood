<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Platform\Domain\Exception\CountryShippedByAnotherStore;
use Modules\Platform\Domain\Repository\StoreCountryRepository;
use Shared\Domain\ValueObject\StoreId;

final readonly class DatabaseStoreCountries implements StoreCountryRepository
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function storeOf(string $countryCode): ?StoreId
    {
        $storeId = $this->db->table('platform.store_countries')->where('country_code', $countryCode)->lockForUpdate()->value('store_id');

        return $storeId === null ? null : StoreId::fromString((string) $storeId);
    }

    public function add(StoreId $store, string $countryCode): void
    {
        try {
            // In a savepoint of its own: a refused insert would otherwise abort the caller's
            // transaction, and the query naming the other store below could not run.
            $this->db->transaction(fn () => $this->db->table('platform.store_countries')->insert([
                'store_id' => $store->value,
                'country_code' => $countryCode,
                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'store_countries_one_store_per_country')) {
                throw $e;
            }

            // The other store is named by its code, which is what a Super Admin knows it by.
            $code = $this->db->table('platform.store_countries as country')
                ->join('platform.stores as store', 'store.id', '=', 'country.store_id')
                ->where('country.country_code', $countryCode)
                ->value('store.code');

            throw new CountryShippedByAnotherStore($countryCode, (string) $code);
        }
    }

    public function remove(StoreId $store, string $countryCode): void
    {
        $this->db->table('platform.store_countries')->where('store_id', $store->value)->where('country_code', $countryCode)->delete();
    }
}

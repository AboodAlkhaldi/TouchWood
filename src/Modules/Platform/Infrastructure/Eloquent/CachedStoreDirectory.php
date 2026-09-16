<?php

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;

/**
 * Every store and currency, loaded in two queries and kept in the shared cache until a
 * handler changes one of them. Nothing is memoised in the process: queue workers live for
 * hours, and a copy held in memory would go stale when another process updates a store.
 *
 * @phpstan-type Translated array{ar: string, en: string}
 * @phpstan-type CurrencyRow array{code: string, exponent: int, name: Translated, abbreviation: Translated, sign: string|null}
 * @phpstan-type StoreRow array{id: string, code: string, name: Translated, country_code: string, currency_code: string, tax_rate_basis_points: int, timezone: string, position: int}
 * @phpstan-type Snapshot array{stores: list<StoreRow>, currencies: array<string, CurrencyRow>}
 */
final readonly class CachedStoreDirectory implements StoreDirectory
{
    private const string CACHE_KEY = 'platform:store-directory:v1';

    public function __construct(
        private Cache $cache,
        private ConnectionInterface $db,
    ) {}

    public function stores(): array
    {
        $snapshot = $this->snapshot();

        return array_map(fn (array $store): StoreDto => $this->storeDto($store, $snapshot), $snapshot['stores']);
    }

    public function storeById(string $id): ?StoreDto
    {
        return $this->findStore('id', strtolower($id));
    }

    public function storeByCode(string $code): ?StoreDto
    {
        return $this->findStore('code', $code);
    }

    public function currency(string $code): ?CurrencyDto
    {
        $currency = $this->snapshot()['currencies'][$code] ?? null;

        return $currency === null ? null : new CurrencyDto(
            $currency['code'],
            $currency['exponent'],
            $this->translated($currency['name']),
            $this->translated($currency['abbreviation']),
            $currency['sign'],
        );
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @param  'id'|'code'  $field
     */
    private function findStore(string $field, string $value): ?StoreDto
    {
        $snapshot = $this->snapshot();

        foreach ($snapshot['stores'] as $store) {
            if ($store[$field] === $value) {
                return $this->storeDto($store, $snapshot);
            }
        }

        return null;
    }

    /**
     * @return Snapshot
     */
    private function snapshot(): array
    {
        /** @var Snapshot */
        return $this->cache->rememberForever(self::CACHE_KEY, fn (): array => $this->load());
    }

    /**
     * @return Snapshot
     */
    private function load(): array
    {
        $currencies = [];

        foreach ($this->db->table('platform.currencies')->get() as $row) {
            $currencies[(string) $row->code] = [
                'code' => (string) $row->code,
                'exponent' => (int) $row->exponent,
                'name' => $this->decode($row->name),
                'abbreviation' => $this->decode($row->abbreviation),
                'sign' => $row->sign === null ? null : (string) $row->sign,
            ];
        }

        $stores = [];

        foreach ($this->db->table('platform.stores')->orderBy('position')->orderBy('code')->get() as $row) {
            $stores[] = [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => $this->decode($row->name),
                'country_code' => (string) $row->country_code,
                'currency_code' => (string) $row->currency_code,
                'tax_rate_basis_points' => (int) $row->tax_rate_basis_points,
                'timezone' => (string) $row->timezone,
                'position' => (int) $row->position,
            ];
        }

        return ['stores' => $stores, 'currencies' => $currencies];
    }

    /**
     * @param  StoreRow  $store
     * @param  Snapshot  $snapshot
     */
    private function storeDto(array $store, array $snapshot): StoreDto
    {
        $currency = $snapshot['currencies'][$store['currency_code']];

        return new StoreDto(
            $store['id'],
            $store['code'],
            $this->translated($store['name']),
            $store['country_code'],
            $store['currency_code'],
            $currency['exponent'],
            $currency['sign'],
            $this->translated($currency['abbreviation']),
            $store['tax_rate_basis_points'],
            $store['timezone'],
            $store['position'],
        );
    }

    /**
     * @param  Translated  $text
     */
    private function translated(array $text): TranslatedTextDto
    {
        return new TranslatedTextDto($text['ar'], $text['en']);
    }

    /**
     * @return Translated
     */
    private function decode(mixed $json): array
    {
        /** @var Translated */
        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }
}

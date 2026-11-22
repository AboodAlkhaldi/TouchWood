<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Infrastructure\Cache\VersionedCache;

/**
 * Every store and currency, loaded in two queries and kept in the shared cache until a
 * handler changes one of them (see VersionedCache). Nothing outlives a request or a job: queue
 * workers live for hours, and a copy held for the process would go stale when another process
 * updates a store. Bound **scoped**, it reads the snapshot once per web request (§9.11, owner
 * 2026-10-08) - the panel's frame asked it twice, 2 queries each. The system (the console, a
 * queued job) reads the cache every time, as before, and so does the rest of a request that
 * changed a store or a currency.
 *
 * @phpstan-type Translated array{ar: string, en: string}
 * @phpstan-type CurrencyRow array{code: string, exponent: int, name: Translated, abbreviation: Translated, sign: string|null}
 * @phpstan-type StoreRow array{id: string, code: string, name: Translated, country_code: string, currency_code: string, tax_rate_basis_points: int, timezone: string, position: int, is_active: bool, is_base: bool}
 * @phpstan-type Snapshot array{stores: list<StoreRow>, currencies: array<string, CurrencyRow>}
 */
final class CachedStoreDirectory implements StoreDirectory
{
    /** Safety net only: every change replaces the version at once. Lifetime set because stores and currencies change rarely (owner, 2026-09-18). */
    private const int SNAPSHOT_SECONDS = 21600;

    private readonly VersionedCache $cache;

    /** The transaction level the request reads at: 0, or the test's own transaction. */
    private readonly int $level;

    /** @var Snapshot|null what this request read */
    private ?array $snapshot = null;

    /** A store or currency changed in this request: never answered from memory again. */
    private bool $changed = false;

    public function __construct(
        Cache $cache,
        private readonly Connection $db,
        private readonly ActorContext $actors,
    ) {
        $this->cache = new VersionedCache($cache, $db, 'platform:store-directory', self::SNAPSHOT_SECONDS);
        $this->level = $db->transactionLevel();
    }

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

    /**
     * @return list<CurrencyDto>
     */
    public function currencies(): array
    {
        $currencies = $this->snapshot()['currencies'];
        ksort($currencies);

        return array_values(array_map(fn (array $currency): CurrencyDto => new CurrencyDto(
            $currency['code'],
            $currency['exponent'],
            $this->translated($currency['name']),
            $this->translated($currency['abbreviation']),
            $currency['sign'],
        ), $currencies));
    }

    public function invalidate(): void
    {
        $this->cache->invalidate();
        $this->changed = true;
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
        // Never inside a transaction opened after the request began: a check made there, after its
        // locks, must see what another process committed meanwhile (access.md amendment 65).
        if ($this->changed
            || $this->actors->current()->type === ActorType::System
            || $this->db->transactionLevel() !== $this->level) {
            /** @var Snapshot */
            return $this->cache->remember(fn (): array => $this->load());
        }

        /** @var Snapshot */
        return $this->snapshot ??= $this->cache->remember(fn (): array => $this->load());
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
                'is_active' => (bool) $row->is_active,
                'is_base' => (bool) $row->is_base,
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
            $store['is_active'],
            $store['is_base'],
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

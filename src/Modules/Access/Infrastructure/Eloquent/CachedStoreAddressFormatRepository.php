<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Modules\Access\Domain\Exception\AccessError;
use Modules\Access\Domain\Model\StoreAddressFormat;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Access\Domain\ValueObject\AddressField;
use Shared\Infrastructure\Cache\VersionedCache;
use stdClass;

/**
 * Each store's address form, cached under its own version (the rule every cache here follows): a
 * change replaces the version inside its transaction, so a checkout never renders an address with
 * yesterday's layout. It is read on every address saved and every address shown, and changed a few
 * times a year.
 */
final readonly class CachedStoreAddressFormatRepository implements StoreAddressFormatRepository
{
    private const string TABLE = 'access.store_address_formats';

    /** Safety net only: a change replaces the version at once (one hour, as the permissions cache). */
    private const int SNAPSHOT_SECONDS = 3600;

    public function __construct(
        private Connection $db,
        private Cache $cache,
    ) {}

    public function forStore(string $storeId): ?StoreAddressFormat
    {
        if (! Ulids::valid($storeId)) {
            return null;
        }

        $storeId = strtolower($storeId);

        /** @var array{fields: list<array<string, mixed>>, template: string}|null $snapshot */
        $snapshot = $this->cacheFor($storeId)->remember(fn (): ?array => $this->load($storeId));

        if ($snapshot === null) {
            return null;
        }

        try {
            return StoreAddressFormat::of(
                $storeId,
                array_map(static fn (array $field): AddressField => AddressField::fromArray($field), $snapshot['fields']),
                $snapshot['template'],
            );
        } catch (AccessError) {
            // Only a row nothing here could have written. A store with no usable format has none:
            // saving an address then says so plainly, and every address of it reads as incomplete.
            return null;
        }
    }

    public function save(StoreAddressFormat $format): void
    {
        $this->db->table(self::TABLE)->upsert([
            [
                'store_id' => strtolower($format->storeId),
                'fields' => json_encode($format->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'display_template' => $format->displayTemplate,
                'updated_at' => CarbonImmutable::now(),
            ],
        ], ['store_id'], ['fields', 'display_template', 'updated_at']);

        $this->cacheFor(strtolower($format->storeId))->invalidate();
    }

    public function existsFor(string $storeId): bool
    {
        return Ulids::valid($storeId) && $this->db->table(self::TABLE)->where('store_id', strtolower($storeId))->exists();
    }

    /**
     * @return array{fields: list<array<string, mixed>>, template: string}|null
     */
    private function load(string $storeId): ?array
    {
        $row = $this->db->table(self::TABLE)->where('store_id', strtolower($storeId))->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $fields = json_decode((string) $row->fields, true, 512, JSON_THROW_ON_ERROR);

        return [
            'fields' => is_array($fields) ? array_values(array_filter($fields, is_array(...))) : [],
            'template' => (string) $row->display_template,
        ];
    }

    private function cacheFor(string $storeId): VersionedCache
    {
        return new VersionedCache($this->cache, $this->db, "access:address-format:{$storeId}", self::SNAPSHOT_SECONDS);
    }
}

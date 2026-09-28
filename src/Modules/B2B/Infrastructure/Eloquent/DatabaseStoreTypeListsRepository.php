<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Domain\Model\StoreTypeLists;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use stdClass;

final readonly class DatabaseStoreTypeListsRepository implements StoreTypeListsRepository
{
    private const string TABLE = 'b2b.store_type_lists';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $storeId): ?StoreTypeLists
    {
        return $this->one($storeId, lock: false);
    }

    public function byStore(string $storeId): ?StoreTypeLists
    {
        return $this->one($storeId, lock: true);
    }

    public function add(StoreTypeLists $lists): void
    {
        $this->db->table(self::TABLE)->insert([
            'store_id' => $lists->storeId(),
            'copied_not_reviewed' => $lists->copiedNotReviewed(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function update(StoreTypeLists $lists): void
    {
        $this->db->table(self::TABLE)->where('store_id', $lists->storeId())->update([
            'copied_not_reviewed' => $lists->copiedNotReviewed(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function one(string $storeId, bool $lock): ?StoreTypeLists
    {
        if (! Ulids::valid($storeId)) {
            return null;
        }

        $query = $this->db->table(self::TABLE)->where('store_id', strtolower($storeId));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? StoreTypeLists::reconstitute((string) $row->store_id, (bool) $row->copied_not_reviewed) : null;
    }
}

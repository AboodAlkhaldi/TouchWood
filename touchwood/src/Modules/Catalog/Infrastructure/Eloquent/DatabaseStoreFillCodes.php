<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\Import\StoreFillCode;
use Modules\Catalog\Application\Import\StoreFillCodes;
use stdClass;

/**
 * A store file's codes in one query (P13): who holds each (`product_codes`), the variant carrying it
 * - a variant's code is always one its product holds (the database's foreign key) -, and whether that
 * variant is on in the store. Reads lock nothing.
 */
final readonly class DatabaseStoreFillCodes implements StoreFillCodes
{
    /** A file holds at most 1,000 codes: one query, the chunk a guard. */
    private const int CHUNK = 1_000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function in(string $storeId, array $codes): array
    {
        $standings = [];

        foreach (array_chunk(array_values(array_unique($codes)), self::CHUNK) as $chunk) {
            $rows = $this->db->table('catalog.product_codes as pc')
                ->leftJoin('catalog.variants as v', 'v.code', '=', 'pc.code')
                ->whereIn('pc.code', $chunk)
                ->select(['pc.code', 'pc.product_id', 'v.id as variant_id', 'v.is_archived'])
                ->selectRaw('EXISTS (SELECT 1 FROM catalog.store_variants sv WHERE sv.store_id = ? AND sv.variant_id = v.id AND sv.is_active) as on_here', [$storeId])
                ->get();

            foreach ($rows as $row) {
                /** @var stdClass $row */
                $standings[(string) $row->code] = new StoreFillCode(
                    (string) $row->code,
                    (string) $row->product_id,
                    $row->variant_id === null ? null : (string) $row->variant_id,
                    (bool) $row->is_archived,
                    (bool) $row->on_here,
                );
            }
        }

        return $standings;
    }
}

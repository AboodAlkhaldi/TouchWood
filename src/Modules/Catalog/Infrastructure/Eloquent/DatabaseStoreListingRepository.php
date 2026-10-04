<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Domain\Model\StoreListing;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\ValueObject\SellingLimits;
use stdClass;

final readonly class DatabaseStoreListingRepository implements StoreListingRepository
{
    private const string PRODUCTS = 'catalog.store_products';

    private const string VARIANTS = 'catalog.store_variants';

    private const string LABELS = 'catalog.store_product_labels';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function of(string $storeId, string $productId): StoreListing
    {
        $row = $this->db->table(self::PRODUCTS)->where('store_id', $storeId)->where('product_id', $productId)->first();

        return $row instanceof stdClass ? $this->read($row) : StoreListing::unchosen($storeId, $productId);
    }

    public function inEveryStore(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        return array_values(array_map(
            fn (stdClass $row): StoreListing => $this->read($row),
            $this->db->table(self::PRODUCTS)->where('product_id', strtolower($productId))->orderBy('store_id')->get()->all(),
        ));
    }

    public function save(StoreListing $listing): void
    {
        if (! $listing->isChosen()) {
            return;
        }

        $now = CarbonImmutable::now();
        $key = ['store_id' => $listing->storeId(), 'product_id' => $listing->productId()];
        $limits = $listing->limits();

        $this->db->table(self::PRODUCTS)->upsert([[
            ...$key,
            'not_available_now' => $listing->notAvailableNow(),
            'retail_minimum' => $limits->retailMinimum,
            'retail_maximum' => $limits->retailMaximum,
            'wholesale_minimum' => $limits->wholesaleMinimum,
            'wholesale_maximum' => $limits->wholesaleMaximum,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['store_id', 'product_id'], ['not_available_now', 'retail_minimum', 'retail_maximum', 'wholesale_minimum', 'wholesale_maximum', 'updated_at']);

        $rows = [];

        foreach ($listing->variants() as $variantId => $variant) {
            $rows[] = [
                ...$key,
                'variant_id' => $variantId,
                'is_active' => $variant['active'],
                'not_available_now' => $variant['unavailable'],
                'sells_retail' => $variant['retail'],
                'sells_wholesale' => $variant['wholesale'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            $this->db->table(self::VARIANTS)->upsert($rows, ['store_id', 'variant_id'], ['is_active', 'not_available_now', 'sells_retail', 'sells_wholesale', 'updated_at']);
        }

        // A label that stays keeps its row: writing it again would take a key lock on the label,
        // which its handler has locked, but a list change may hold otherwise.
        $labels = $listing->labelIds();
        $this->db->table(self::LABELS)->where($key)->whereNotIn('label_id', $labels)->delete();
        $held = array_map('strval', $this->db->table(self::LABELS)->where($key)->pluck('label_id')->all());
        $new = array_values(array_diff($labels, $held));

        if ($new !== []) {
            $this->db->table(self::LABELS)->insert(array_map(static fn (string $labelId): array => [...$key, 'label_id' => $labelId], $new));
        }
    }

    public function activeStoresOf(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        return array_values(array_map('strval', $this->db->table(self::VARIANTS)
            ->where('product_id', strtolower($productId))
            ->where('is_active', true)
            ->distinct()
            ->orderBy('store_id')
            ->pluck('store_id')
            ->all()));
    }

    public function anyWithLabel(string $labelId): bool
    {
        return Ulids::valid($labelId) && $this->db->table(self::LABELS)->where('label_id', strtolower($labelId))->exists();
    }

    private function read(stdClass $row): StoreListing
    {
        $storeId = (string) $row->store_id;
        $productId = (string) $row->product_id;
        $variants = [];

        foreach ($this->db->table(self::VARIANTS)->where('store_id', $storeId)->where('product_id', $productId)->orderBy('variant_id')->get() as $variant) {
            $variants[(string) $variant->variant_id] = [
                'active' => (bool) $variant->is_active,
                'unavailable' => (bool) $variant->not_available_now,
                'retail' => (bool) $variant->sells_retail,
                'wholesale' => (bool) $variant->sells_wholesale,
            ];
        }

        // The card shows labels in the list's order (catalog.md §1.8).
        $labelIds = array_values(array_map('strval', $this->db->table(self::LABELS.' as attached')
            ->join('catalog.labels as label', 'label.id', '=', 'attached.label_id')
            ->where('attached.store_id', $storeId)
            ->where('attached.product_id', $productId)
            ->orderBy('label.position')
            ->orderBy('label.id')
            ->pluck('attached.label_id')
            ->all()));

        return StoreListing::reconstitute(
            $storeId,
            $productId,
            (bool) $row->not_available_now,
            SellingLimits::reconstitute(
                (int) $row->retail_minimum,
                $row->retail_maximum === null ? null : (int) $row->retail_maximum,
                $row->wholesale_minimum === null ? null : (int) $row->wholesale_minimum,
                $row->wholesale_maximum === null ? null : (int) $row->wholesale_maximum,
            ),
            $variants,
            $labelIds,
        );
    }
}

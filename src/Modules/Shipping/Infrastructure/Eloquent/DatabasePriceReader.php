<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Shipping\Application\Price\PriceReader;

final readonly class DatabasePriceReader implements PriceReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function onPlaces(string $storeId, string $countryCode, array $placeIds): array
    {
        if ($placeIds === []) {
            return [];
        }

        $prices = [];

        foreach ($this->priced($storeId, $countryCode)->whereIn('place.place_id', $placeIds)->get(['place.carrier_id', 'place.place_id', 'grp.amount_minor']) as $row) {
            $prices[] = ['carrierId' => (string) $row->carrier_id, 'placeId' => (string) $row->place_id, 'amountMinor' => (int) $row->amount_minor];
        }

        return $prices;
    }

    public function pricedPlaces(string $storeId, string $countryCode): array
    {
        $ids = [];

        foreach ($this->priced($storeId, $countryCode)->distinct()->pluck('place.place_id') as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    private function priced(string $storeId, string $countryCode): Builder
    {
        return $this->db->table('shipping.price_places as place')
            ->join('shipping.price_groups as grp', 'grp.id', '=', 'place.group_id')
            ->join('shipping.carriers as carrier', 'carrier.id', '=', 'place.carrier_id')
            ->where('carrier.store_id', strtolower($storeId))
            ->where('carrier.country_code', strtoupper($countryCode))
            ->where('carrier.active', true);
    }
}

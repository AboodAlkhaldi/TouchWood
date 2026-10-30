<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Shipping\Domain\Model\PriceGroup;
use Modules\Shipping\Domain\Repository\PriceGroupRepository;

final readonly class DatabasePriceGroupRepository implements PriceGroupRepository
{
    private const int CHUNK = 1000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function ofCarrier(string $carrierId): array
    {
        $places = [];

        foreach ($this->db->table('shipping.price_places')->where('carrier_id', $carrierId)->get(['group_id', 'place_id']) as $row) {
            $places[(string) $row->group_id][] = (string) $row->place_id;
        }

        $groups = [];

        foreach ($this->db->table('shipping.price_groups')->where('carrier_id', $carrierId)->orderBy('amount_minor')->get() as $row) {
            $groups[] = PriceGroup::of((string) $row->id, (string) $row->carrier_id, (string) $row->store_id, (int) $row->amount_minor, $places[(string) $row->id] ?? []);
        }

        return $groups;
    }

    public function saveCarrier(string $carrierId, array $groups): void
    {
        $now = CarbonImmutable::now();
        $was = [];

        foreach ($this->ofCarrier($carrierId) as $group) {
            $was[$group->id] = $group;
        }

        $kept = [];

        foreach ($groups as $group) {
            $kept[$group->id] = $group;
        }

        // Groups no longer there go first, their places with them (the key cascades).
        $gone = array_keys(array_diff_key($was, $kept));

        if ($gone !== []) {
            $this->db->table('shipping.price_groups')->whereIn('id', $gone)->delete();
        }

        // Places leaving a group that stays, before any arrives: a place moved between two groups
        // would otherwise meet itself in rule 3's key.
        $arriving = [];

        foreach ($kept as $id => $group) {
            $before = isset($was[$id]) ? $was[$id]->placeIds() : [];
            $leaving = array_values(array_diff($before, $group->placeIds()));

            foreach (array_chunk($leaving, self::CHUNK) as $chunk) {
                $this->db->table('shipping.price_places')->where('group_id', $id)->whereIn('place_id', $chunk)->delete();
            }

            foreach (array_diff($group->placeIds(), $before) as $placeId) {
                $arriving[] = ['store_id' => $group->storeId, 'group_id' => $id, 'carrier_id' => $carrierId, 'place_id' => $placeId];
            }
        }

        // One save changes one group's amount at most, and a group changed to an amount another group
        // has is merged into that one and deleted above - so no amount meets another in rule 4's key.
        foreach ($kept as $id => $group) {
            if (isset($was[$id])) {
                if ($was[$id]->amountMinor() !== $group->amountMinor() || $was[$id]->placeIds() !== $group->placeIds()) {
                    $this->db->table('shipping.price_groups')->where('id', $id)->update(['amount_minor' => $group->amountMinor(), 'updated_at' => $now]);
                }

                continue;
            }

            $this->db->table('shipping.price_groups')->insert([
                'id' => $id,
                'carrier_id' => $carrierId,
                'store_id' => $group->storeId,
                'amount_minor' => $group->amountMinor(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (array_chunk($arriving, self::CHUNK) as $chunk) {
            $this->db->table('shipping.price_places')->insert($chunk);
        }
    }
}

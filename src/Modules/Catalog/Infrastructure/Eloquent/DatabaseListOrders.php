<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LogicException;
use Modules\Catalog\Domain\Repository\ListOrders;
use Modules\Catalog\Domain\ValueObject\ListPosition;

final readonly class DatabaseListOrders implements ListOrders
{
    /** @var array<string, string> each list's table */
    private const array TABLES = [
        self::BRANDS => 'catalog.brands',
        self::LABELS => 'catalog.labels',
        self::WARRANTIES => 'catalog.warranties',
        self::ATTRIBUTES => 'catalog.attributes',
        self::VALUES => 'catalog.attribute_values',
    ];

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function positions(string $list, ?string $attributeId = null): array
    {
        $positions = [];

        foreach ($this->rows($list, $attributeId)->orderBy('position')->orderBy('name_en')->orderBy('id')->get(['id', 'position']) as $row) {
            $positions[(string) $row->id] = (int) $row->position;
        }

        return $positions;
    }

    public function afterLast(string $list, ?string $attributeId = null): int
    {
        $last = $this->rows($list, $attributeId)->max('position');
        $next = (is_numeric($last) ? (int) $last : 0) + 1;

        if ($next <= ListPosition::MAX) {
            return $next;
        }

        // Only a list never ordered since its places were typed reaches the cap: numbered again in
        // its order, every item keeps its place among the others.
        $ids = array_keys($this->positions($list, $attributeId));
        $this->write($list, array_combine($ids, range(1, count($ids))));

        return ListPosition::check(count($ids) + 1);
    }

    public function write(string $list, array $positions): void
    {
        if ($positions === []) {
            return;
        }

        $rows = [];
        $bindings = [CarbonImmutable::now()];

        foreach ($positions as $id => $position) {
            $rows[] = '(?::char(26), ?::integer)';
            array_push($bindings, strtolower((string) $id), $position);
        }

        // One statement, however long the list: never one write a row.
        $this->db->update(
            'UPDATE '.self::table($list).' AS t SET position = v.position, updated_at = ?'
            .' FROM (VALUES '.implode(', ', $rows).') AS v (id, position) WHERE t.id = v.id',
            $bindings,
        );
    }

    private function rows(string $list, ?string $attributeId): Builder
    {
        $query = $this->db->table(self::table($list));

        if ($list !== self::VALUES) {
            return $query;
        }

        if ($attributeId === null) {
            throw new LogicException('An attribute\'s values are named with the attribute.');
        }

        return $query->where('attribute_id', strtolower($attributeId));
    }

    private static function table(string $list): string
    {
        return self::TABLES[$list] ?? throw new LogicException("No ordered list called {$list}.");
    }
}

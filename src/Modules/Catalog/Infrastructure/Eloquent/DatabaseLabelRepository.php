<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\Label;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\ValueObject\LabelTone;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use stdClass;

final readonly class DatabaseLabelRepository implements LabelRepository
{
    private const string TABLE = 'catalog.labels';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $labelId): ?Label
    {
        return $this->read($labelId, false);
    }

    public function byId(string $labelId): ?Label
    {
        return $this->read($labelId, true);
    }

    public function add(Label $label): void
    {
        $now = CarbonImmutable::now();
        $this->db->table(self::TABLE)->insert(['id' => $label->id(), ...self::toRow($label), 'created_at' => $now, 'updated_at' => $now]);
    }

    public function update(Label $label): void
    {
        $this->db->table(self::TABLE)->where('id', $label->id())->update([...self::toRow($label), 'updated_at' => CarbonImmutable::now()]);
    }

    public function delete(string $labelId): void
    {
        $this->db->table(self::TABLE)->where('id', strtolower($labelId))->delete();
    }

    public function all(): array
    {
        return array_values(array_map(
            static fn (stdClass $row): Label => self::toLabel($row),
            $this->db->table(self::TABLE)->orderBy('position')->orderBy('name_en')->orderBy('id')->get()->all(),
        ));
    }

    private function read(string $labelId, bool $lock): ?Label
    {
        if (! Ulids::valid($labelId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($labelId))->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row instanceof stdClass ? self::toLabel($row) : null;
    }

    private static function toLabel(stdClass $row): Label
    {
        return Label::reconstitute(
            (string) $row->id,
            LocalizedName::reconstitute((string) $row->name_ar, (string) $row->name_en),
            LabelTone::from((string) $row->tone),
            (int) $row->position,
            (bool) $row->is_active,
        );
    }

    /**
     * @return array<string, string|int|bool>
     */
    private static function toRow(Label $label): array
    {
        return [
            'name_ar' => $label->name()->ar,
            'name_en' => $label->name()->en,
            'tone' => $label->tone()->value,
            'position' => $label->position(),
            'is_active' => $label->isActive(),
        ];
    }
}

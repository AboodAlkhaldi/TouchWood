<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\Warranty;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Domain\ValueObject\WarrantyPeriod;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

final readonly class DatabaseWarrantyRepository implements WarrantyRepository
{
    private const string TABLE = 'catalog.warranties';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $warrantyId): ?Warranty
    {
        return $this->read($warrantyId, false);
    }

    public function byId(string $warrantyId): ?Warranty
    {
        return $this->read($warrantyId, true);
    }

    public function add(Warranty $warranty): void
    {
        $now = CarbonImmutable::now();
        $this->db->table(self::TABLE)->insert(['id' => $warranty->id(), ...self::toRow($warranty), 'created_at' => $now, 'updated_at' => $now]);
    }

    public function update(Warranty $warranty): void
    {
        $this->db->table(self::TABLE)->where('id', $warranty->id())->update([...self::toRow($warranty), 'updated_at' => CarbonImmutable::now()]);
    }

    public function delete(string $warrantyId): void
    {
        $this->db->table(self::TABLE)->where('id', strtolower($warrantyId))->delete();
    }

    public function all(): array
    {
        return array_values(array_map(
            static fn (stdClass $row): Warranty => self::toWarranty($row),
            $this->db->table(self::TABLE)->orderBy('name_en')->orderBy('id')->get()->all(),
        ));
    }

    private function read(string $warrantyId, bool $lock): ?Warranty
    {
        if (! Ulids::valid($warrantyId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($warrantyId))->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row instanceof stdClass ? self::toWarranty($row) : null;
    }

    private static function toWarranty(stdClass $row): Warranty
    {
        return Warranty::reconstitute(
            (string) $row->id,
            LocalizedName::reconstitute((string) $row->name_ar, (string) $row->name_en),
            StructuredText::fromJson('terms_ar', (string) $row->terms_ar, Warranty::TERMS_MAX),
            StructuredText::fromJson('terms_en', (string) $row->terms_en, Warranty::TERMS_MAX),
            WarrantyPeriod::of($row->period_months === null ? null : (int) $row->period_months),
            (bool) $row->is_active,
        );
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private static function toRow(Warranty $warranty): array
    {
        return [
            'name_ar' => $warranty->name()->ar,
            'name_en' => $warranty->name()->en,
            'terms_ar' => $warranty->termsAr()->toJson(),
            'terms_en' => $warranty->termsEn()->toJson(),
            'period_months' => $warranty->period()->months,
            'is_active' => $warranty->isActive(),
        ];
    }
}

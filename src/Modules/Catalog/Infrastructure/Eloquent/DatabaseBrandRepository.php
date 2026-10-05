<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\Brand;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\Slugs;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Public\Enums\AgencyType;
use stdClass;

final readonly class DatabaseBrandRepository implements BrandRepository
{
    private const string TABLE = 'catalog.brands';

    private SlugHistory $slugs;

    public function __construct(
        private ConnectionInterface $db,
    ) {
        $this->slugs = new SlugHistory($db, 'catalog.brand_slugs', 'brand_id');
    }

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $brandId): ?Brand
    {
        return $this->read($brandId, false);
    }

    public function byId(string $brandId): ?Brand
    {
        return $this->read($brandId, true);
    }

    public function defaultBrand(): ?Brand
    {
        $row = $this->db->table(self::TABLE)->where('is_default', true)->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toBrands([$row])[0] : null;
    }

    public function slugTaken(string $locale, string $slug, ?string $exceptBrandId = null): bool
    {
        return $this->slugs->taken($locale, $slug, $exceptBrandId);
    }

    public function add(Brand $brand): void
    {
        $now = CarbonImmutable::now();
        $this->db->table(self::TABLE)->insert(['id' => $brand->id(), ...self::toRow($brand), 'created_at' => $now, 'updated_at' => $now]);
        $this->slugs->record($brand->id(), $brand->slugs());
    }

    public function update(Brand $brand): void
    {
        $this->db->table(self::TABLE)->where('id', $brand->id())->update([...self::toRow($brand), 'updated_at' => CarbonImmutable::now()]);
        $this->slugs->record($brand->id(), $brand->slugs());
    }

    public function delete(string $brandId): void
    {
        $this->db->table(self::TABLE)->where('id', strtolower($brandId))->delete();
    }

    public function all(): array
    {
        return $this->toBrands($this->db->table(self::TABLE)->orderBy('position')->orderBy('name_en')->orderBy('id')->get()->all());
    }

    public function numbers(): array
    {
        $numbers = [];

        foreach ($this->db->table(self::TABLE)->orderBy('number')->get(['number', 'id']) as $row) {
            $numbers[(int) $row->number] = (string) $row->id;
        }

        return $numbers;
    }

    public function withLogo(string $mediaId): array
    {
        return array_values(array_map('strval', $this->db->table(self::TABLE)->where('logo_media_id', strtolower($mediaId))->orderBy('id')->pluck('id')->all()));
    }

    private function read(string $brandId, bool $lock): ?Brand
    {
        if (! Ulids::valid($brandId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($brandId))->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row instanceof stdClass ? $this->toBrands([$row])[0] : null;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<Brand>
     */
    private function toBrands(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));
        $slugs = $this->slugs->currentOf(array_map(static fn (stdClass $row): string => (string) $row->id, $rows));

        return array_map(static fn (stdClass $row): Brand => Brand::reconstitute(
            (string) $row->id,
            LocalizedName::reconstitute((string) $row->name_ar, (string) $row->name_en),
            Slugs::reconstitute($slugs[(string) $row->id]['ar'] ?? '', $slugs[(string) $row->id]['en'] ?? ''),
            $row->description_ar === null ? null : StructuredText::fromJson('description_ar', (string) $row->description_ar, Brand::DESCRIPTION_MAX),
            $row->description_en === null ? null : StructuredText::fromJson('description_en', (string) $row->description_en, Brand::DESCRIPTION_MAX),
            $row->logo_media_id === null ? null : (string) $row->logo_media_id,
            $row->origin_country === null ? null : (string) $row->origin_country,
            AgencyType::from((string) $row->agency_type),
            (bool) $row->is_default,
            (bool) $row->show_in_default_listings,
            (int) $row->position,
            (bool) $row->is_active,
        ), $rows);
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private static function toRow(Brand $brand): array
    {
        return [
            'name_ar' => $brand->name()->ar,
            'name_en' => $brand->name()->en,
            'description_ar' => $brand->descriptionAr()?->toJson(),
            'description_en' => $brand->descriptionEn()?->toJson(),
            'logo_media_id' => $brand->logoMediaId(),
            'origin_country' => $brand->originCountry(),
            'agency_type' => $brand->agencyType()->value,
            'is_default' => $brand->isDefault(),
            'show_in_default_listings' => $brand->showInDefaultListings(),
            'position' => $brand->position(),
            'is_active' => $brand->isActive(),
        ];
    }
}

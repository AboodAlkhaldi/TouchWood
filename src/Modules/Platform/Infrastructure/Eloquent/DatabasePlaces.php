<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Domain\Model\Place;
use Modules\Platform\Domain\Repository\PlaceRepository;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\Enums\PlaceKind;

final readonly class DatabasePlaces implements PlaceRepository
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function countryOf(string $id): ?string
    {
        if (! Str::isUlid($id)) {
            return null;
        }

        $code = $this->db->table('platform.places')->where('id', strtolower($id))->value('country_code');

        return $code === null ? null : (string) $code;
    }

    public function byId(string $id): ?Place
    {
        if (! Str::isUlid($id)) {
            return null;
        }

        $row = $this->db->table('platform.places')->where('id', strtolower($id))->lockForUpdate()->first();

        if ($row === null) {
            return null;
        }

        /** @var array{ar: string, en: string} $name */
        $name = json_decode((string) $row->name, true, flags: JSON_THROW_ON_ERROR);

        return Place::reconstitute(
            (string) $row->id,
            (string) $row->country_code,
            PlaceKind::from((string) $row->kind),
            $row->parent_id === null ? null : (string) $row->parent_id,
            TranslatedText::of($name['ar'], $name['en'], 'name'),
            (bool) $row->retired,
        );
    }

    public function add(Place $place): void
    {
        $this->db->table('platform.places')->insert([
            'id' => $place->id,
            'country_code' => $place->countryCode,
            'kind' => $place->kind->value,
            'parent_id' => $place->parentId,
            'name' => json_encode(['ar' => $place->name()->ar, 'en' => $place->name()->en], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'retired' => $place->isRetired(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function update(Place $place): void
    {
        $this->db->table('platform.places')->where('id', $place->id)->update([
            'name' => json_encode(['ar' => $place->name()->ar, 'en' => $place->name()->en], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'retired' => $place->isRetired(),
            'updated_at' => now(),
        ]);
    }

    public function nameTaken(string $countryCode, PlaceKind $kind, ?string $parentId, TranslatedText $name, ?string $exceptId = null): ?string
    {
        foreach (['ar', 'en'] as $language) {
            $taken = $this->db->table('platform.places')
                ->where('country_code', $countryCode)
                ->where('kind', $kind->value)
                ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
                ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
                ->whereRaw("lower(name->>'{$language}') = lower(?)", [$name->{$language}])
                ->exists();

            if ($taken) {
                return $name->{$language};
            }
        }

        return null;
    }
}

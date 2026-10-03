<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Domain\ValueObject\Slugs;

/**
 * Every slug a brand or a category ever held (catalog.md §1.1, §9.3 #3): one row per (locale, slug),
 * one current per owner and locale. An old slug stays a row, so it keeps answering with a redirect
 * and is never given to another; an owner going back to a slug it once held takes its own row back.
 */
final readonly class SlugHistory
{
    public function __construct(
        private ConnectionInterface $db,
        private string $table,
        private string $owner,
    ) {}

    public function taken(string $locale, string $slug, ?string $exceptOwnerId): bool
    {
        return $this->db->table($this->table)
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->when($exceptOwnerId !== null, fn ($query) => $query->where($this->owner, '<>', strtolower((string) $exceptOwnerId)))
            ->exists();
    }

    /**
     * Makes these the owner's current slugs, keeping every one it had.
     */
    public function record(string $ownerId, Slugs $slugs): void
    {
        $this->recordLocales($ownerId, ['ar' => $slugs->ar->value, 'en' => $slugs->en->value]);
    }

    /**
     * As record(), for an owner that may lack a locale's slug — a draft product named in Arabic only
     * (catalog.md amendment 3(g)); a locale not given keeps whatever it has.
     *
     * @param  array<string, string>  $slugs  locale => slug
     */
    public function recordLocales(string $ownerId, array $slugs): void
    {
        foreach ($slugs as $locale => $slug) {
            $current = $this->db->table($this->table)->where($this->owner, $ownerId)->where('locale', $locale)->where('is_current', true)->value('slug');

            if ($current === $slug) {
                continue;
            }

            $this->db->table($this->table)->where($this->owner, $ownerId)->where('locale', $locale)->update(['is_current' => false]);

            $held = $this->db->table($this->table)->where($this->owner, $ownerId)->where('locale', $locale)->where('slug', $slug)->exists();

            if ($held) {
                $this->db->table($this->table)->where($this->owner, $ownerId)->where('locale', $locale)->where('slug', $slug)->update(['is_current' => true]);
            } else {
                $this->db->table($this->table)->insert([
                    'locale' => $locale,
                    'slug' => $slug,
                    $this->owner => $ownerId,
                    'is_current' => true,
                    'created_at' => CarbonImmutable::now(),
                ]);
            }
        }
    }

    /**
     * The owner's current slugs, as [owner id => [ar, en]].
     *
     * @param  list<string>  $ownerIds
     * @return array<string, array{ar: string, en: string}>
     */
    public function currentOf(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $slugs = [];

        foreach ($this->db->table($this->table)->whereIn($this->owner, $ownerIds)->where('is_current', true)->get() as $row) {
            $slugs[(string) $row->{$this->owner}][(string) $row->locale] = (string) $row->slug;
        }

        return array_map(static fn (array $pair): array => ['ar' => $pair['ar'] ?? '', 'en' => $pair['en'] ?? ''], $slugs);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\External;

use DateTimeImmutable;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\Query\Shop\ShopReads;
use Modules\Platform\Public\Contracts\ShopPolicies;
use Modules\Platform\Public\Dto\ShopPolicyDto;
use Modules\Platform\Public\Enums\PolicyKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * Platform's `ShopPolicies`, answered by Content, which keeps the policies (flags-2026-11.md S3): a store's published
 * policies, read in **one query** (`ShopReads::policies`) and kept in Content's never-stale cache
 * (`policies:{store}`, both languages), which every policy's change replaces inside its transaction. **A warm read is
 * the cache's two queries** (its version and the copy); nothing is kept past the call (lesson 67).
 *
 * The version is the policy's published text's number (F14) and `updatedAt` the moment that text was published (F15).
 * Asked for Other, the first in the store's order: the Other made first (the builder's pick).
 */
final readonly class ContentShopPolicies implements ShopPolicies
{
    public function __construct(
        private ContentCache $cache,
        private ShopReads $reads,
    ) {}

    public function published(StoreId $store, PolicyKind $kind, string $locale): ?ShopPolicyDto
    {
        foreach ($this->rows($store) as $row) {
            if ($row['kind'] === $kind->value) {
                return self::dto($row, $locale);
            }
        }

        return null;
    }

    public function allPublished(StoreId $store, string $locale): array
    {
        return array_map(static fn (array $row): ShopPolicyDto => self::dto($row, $locale), $this->rows($store));
    }

    public function versionAddresses(StoreId $store, array $versions, string $locale): array
    {
        // Read as it is now, never cached: a staff page asks it, a version or two at a time.
        return array_map(static fn (array $slugs): string => $locale === 'ar' ? $slugs['ar'] : $slugs['en'], $this->reads->policyVersionSlugs($store->value, $versions));
    }

    /**
     * @return list<array{id: string, kind: string, version: int, updated: string, title_ar: string, slug_ar: string, title_en: string|null, slug_en: string|null}>
     */
    private function rows(StoreId $store): array
    {
        return $this->cache->remember("policies:{$store->value}", fn (): array => $this->reads->policies($store->value));
    }

    /**
     * @param  array{id: string, kind: string, version: int, updated: string, title_ar: string, slug_ar: string, title_en: string|null, slug_en: string|null}  $row
     */
    private static function dto(array $row, string $locale): ShopPolicyDto
    {
        // A published policy has both languages (S3.1); the Arabic stands in should one ever lack its English.
        $english = $locale === 'en' && $row['title_en'] !== null && $row['slug_en'] !== null;

        return new ShopPolicyDto(
            $row['id'],
            PolicyKind::from($row['kind']),
            $row['version'],
            $english ? (string) $row['title_en'] : $row['title_ar'],
            $english ? (string) $row['slug_en'] : $row['slug_ar'],
            new DateTimeImmutable($row['updated']),
        );
    }
}

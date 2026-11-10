<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Seo;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Repository\StoreSeo;
use Modules\Content\Domain\Repository\WaitingJobs;
use Shared\Application\Unauthorized;

/**
 * What W10 shows for a store (content.md §4.4): its defaults as saved (none yet: empty boxes), its robots lines, and
 * its sitemap - when it was last built, how many addresses it holds, and whether a rebuild waits.
 */
final readonly class SeoHandler
{
    public const string PERMISSION = ContentPermissions::SEO_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private StoreSeo $seo,
        private WaitingJobs $waiting,
    ) {}

    /**
     * @return array{defaults: array<string, string|null>|null, sitemap: array{builtAt: string|null, urls: int, parts: int, rebuildWaits: bool}}
     *
     * @throws ContentError|Unauthorized
     */
    public function page(?string $storeId): array
    {
        $store = $this->access->authorize(self::PERMISSION, $storeId);
        $defaults = $this->seo->defaults($store->id);
        $parts = $this->seo->sitemapParts()[$store->id] ?? [];
        $built = $parts === [] ? null : max(array_map(static fn (array $part): string => $part['built_at']->format(DATE_ATOM), $parts));

        return [
            'defaults' => $defaults?->fields(),
            'sitemap' => [
                'builtAt' => $built,
                'urls' => array_sum(array_column($parts, 'urls')),
                'parts' => count($parts),
                'rebuildWaits' => $this->waiting->waitingSince(WaitingJobs::BUILD_SITEMAPS) !== null,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\BuildSitemaps;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Sitemap\SitemapBuilder;
use Modules\Content\Application\Sitemap\SitemapFiles;
use Modules\Content\Domain\Repository\StoreSeo;
use Modules\Content\Domain\Repository\WaitingJobs;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Builds every on store's sitemap again (content.md §1.14, §5): **stored as files, never made in a shopper's
 * request**. Each store's files are written first, then its rows replaced in one transaction, then the files they
 * replaced deleted - so a reader finds either the old files or the new, never a gap. **A store that is off leaves
 * the index**: its rows and files go.
 *
 * The system's, reserved (`content.sitemap.build`): nightly at 03:10 (plan K10), on demand from W10's Rebuild
 * Sitemap, on a store switched on or off, and at most once a day more for Catalog's changes (§6).
 */
final readonly class BuildSitemapsHandler
{
    public const string PERMISSION = ContentPermissions::SITEMAP_BUILD;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private SitemapBuilder $builder,
        private SitemapFiles $files,
        private StoreSeo $seo,
        private WaitingJobs $waiting,
        private ConnectionInterface $db,
        private Config $config,
    ) {}

    /**
     * @return array<string, int> each on store's code => how many addresses its sitemap holds
     *
     * @throws Unauthorized
     */
    public function handle(BuildSitemaps $command): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        // Under way: a change from now on asks for another.
        $this->waiting->started(WaitingJobs::BUILD_SITEMAPS);

        $root = rtrim((string) $this->config->get('app.url'), '/');
        $onStores = $this->platform->stores();
        $built = [];

        foreach ($onStores as $store) {
            $parts = [];
            $run = strtolower((string) Str::ulid());

            foreach ($this->builder->build($store, $onStores, $root) as $index => $file) {
                $key = "content/sitemaps/{$store->id}/{$run}-".($index + 1).'.xml';
                $this->files->put($key, $file['xml']);
                $parts[$index + 1] = ['file_key' => $key, 'urls' => $file['urls']];
            }

            $replaced = $this->db->transaction(fn (): array => $this->seo->replaceSitemap($store->id, $parts, Moments::now()), 3);
            $this->files->delete($replaced);
            $built[$store->code] = array_sum(array_column($parts, 'urls'));
        }

        $removed = $this->db->transaction(fn (): array => $this->seo->removeSitemapsBut(array_map(static fn ($store): string => $store->id, $onStores)), 3);
        $this->files->delete($removed);

        return $built;
    }
}

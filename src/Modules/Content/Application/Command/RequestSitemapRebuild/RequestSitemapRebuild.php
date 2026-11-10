<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\RequestSitemapRebuild;

/** W10's Rebuild Sitemap, pressed in a store's SEO screen (content.md §1.14). */
final readonly class RequestSitemapRebuild
{
    public function __construct(
        public string $storeId,
    ) {}
}

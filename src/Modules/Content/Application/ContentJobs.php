<?php

declare(strict_types=1);

namespace Modules\Content\Application;

/**
 * Content's queued work, asked for from its use cases (content.md §3): the jobs are the Infrastructure's, so a use
 * case asks through this port. Each is queued after the asking transaction commits.
 */
interface ContentJobs
{
    /** `BuildSitemapsJob`: every on store's sitemap built again. */
    public function buildSitemaps(): void;

    /** `CheckRedirectsJob`: the redirects' new addresses asked, a page at a time, from after this old address. */
    public function checkRedirects(?string $after = null): void;
}

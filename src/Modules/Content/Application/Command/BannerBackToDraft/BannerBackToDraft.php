<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\BannerBackToDraft;

/** **Back to Draft** (content.md §4.1, W3): a scheduled banner, not yet live, is a draft again. */
final readonly class BannerBackToDraft
{
    public function __construct(
        public string $bannerId,
    ) {}
}

<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * One client logo of the home page's logos band (content.md §1.4 `CLIENT_LOGOS`): the file, the client's name as
 * its description per language, an optional link; ordered by dragging.
 */
final readonly class HomeLogo
{
    public function __construct(
        public string $mediaId,
        public string $nameAr,
        public ?string $nameEn,
        public ?string $link,
    ) {}
}

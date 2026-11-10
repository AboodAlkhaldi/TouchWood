<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * Where a banner shows (content.md §1.6's table): behind the home hero on desktop and tablet, the swiped row on a
 * phone's home, or above product lists. Each takes its own artwork and shows a few at once, the first in order.
 */
enum BannerPlacement: string
{
    case HomeHero = 'HOME_HERO';
    case HomePhone = 'HOME_PHONE';
    case Listing = 'LISTING';

    /** How many live banners show at once (§1.6): more may be live; only the first in order show. */
    public function liveAtOnce(): int
    {
        return $this === self::HomePhone ? 3 : 1;
    }

    /** Whether it takes a desktop image (§1.6: phones use `HOME_PHONE`). */
    public function takesDesktop(): bool
    {
        return $this !== self::HomePhone;
    }

    /** Whether it takes a phone image. */
    public function takesPhone(): bool
    {
        return $this !== self::HomeHero;
    }
}

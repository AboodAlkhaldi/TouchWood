<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * Where a banner shows (content.md §1.6's table): the home's first section, turning, at every width (shop-design.md
 * §3.1: one list for both widths since 2026-11-16, `HOME_PHONE` gone), or above product lists. Each takes a wide and a
 * phone image and shows a few at once, the first in order.
 */
enum BannerPlacement: string
{
    case HomeHero = 'HOME_HERO';
    case Listing = 'LISTING';

    /** How many live banners show at once (§1.6; shop-design.md P2): more may be live; only the first in order show. */
    public function liveAtOnce(): int
    {
        return $this === self::HomeHero ? 5 : 1;
    }

    /** Whether it takes a desktop image: every placement does. */
    public function takesDesktop(): bool
    {
        return true;
    }

    /** Whether it takes a phone image: every placement does. */
    public function takesPhone(): bool
    {
        return true;
    }

    /** Whether it carries its own words over its picture - a title, a line and a button (shop-design.md §3.1). */
    public function takesWords(): bool
    {
        return $this === self::HomeHero;
    }
}

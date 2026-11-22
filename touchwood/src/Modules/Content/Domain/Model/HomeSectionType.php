<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * The home page's fixed section types (content.md §1.4), in the starting order: the prototype's, with bundles and
 * "picked for you" dropped (owner, 2026-11-03). No free page builder (round 1 #4): each type at most once a store.
 */
enum HomeSectionType: string
{
    case Hero = 'HERO';
    case Promises = 'PROMISES';
    case Categories = 'CATEGORIES';
    case BestSellers = 'BEST_SELLERS';
    case Projects = 'PROJECTS';
    case Audience = 'AUDIENCE';
    case Points = 'POINTS';
    case ClientLogos = 'CLIENT_LOGOS';

    /** Its place in the starting order. */
    public function startingPosition(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /** The audit's and the words' name for it. */
    public function key(): string
    {
        return strtolower($this->value);
    }
}

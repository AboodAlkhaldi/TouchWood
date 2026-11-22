<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * The 11 parts of a theme (content.md §1.8), in its table's order, each with the image slots it takes and whether it
 * holds words. A part takes artwork only, never a colour (Q7 (a)).
 */
enum ThemePartKind: string
{
    case Announcement = 'ANNOUNCEMENT';
    case Hero = 'HERO';
    case Promises = 'PROMISES';
    case Points = 'POINTS';
    case Logos = 'LOGOS';
    case Flag = 'FLAG';
    case Badge = 'BADGE';
    case Listing = 'LISTING';
    case Cart = 'CART';
    case Footer = 'FOOTER';
    case Backdrop = 'BACKDROP';

    /**
     * The image slots this part takes (`theme_part_images.slot`).
     *
     * @return list<string>
     */
    public function slots(): array
    {
        return match ($this) {
            self::Announcement, self::Hero, self::Listing => ['DESKTOP', 'PHONE'],
            self::Promises, self::Points, self::Logos, self::Footer => ['BAND'],
            self::Flag => ['FLAG', 'RIBBON'],
            self::Backdrop => ['START', 'END'],
            self::Badge, self::Cart => [],
        };
    }

    /** Artwork that may carry words is uploaded per language (§1.8: the hero, the listing banner). */
    public function imagesPerLanguage(): bool
    {
        return $this === self::Hero || $this === self::Listing;
    }

    /** A transparent part (6, 11): WebP or PNG only, never drawn from Platform's JPEG sizes (§1.8). */
    public function isTransparent(): bool
    {
        return $this === self::Flag || $this === self::Backdrop;
    }

    /** The most characters its words take (each language), or 0 for a part with no words of its own line. */
    public function maxText(): int
    {
        return match ($this) {
            self::Badge => 24,
            self::Announcement, self::Cart => 120,
            self::Points, self::Logos => 80,
            default => 0,
        };
    }

    /** Whether its words may carry a link (§1.8: the announcement strip, the cart message). */
    public function takesLink(): bool
    {
        return $this === self::Announcement || $this === self::Cart;
    }
}

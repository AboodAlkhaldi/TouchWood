<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Shop;

use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Platform\Public\Enums\MediaSize;

/**
 * **`theme.style`** (content.md §1.8 "How artwork reaches the page", "Safe to print"): the custom properties a live
 * theme's background parts set, written by the server **from media sizes only, never from anything typed** - the
 * shell prints it unescaped (`app.blade.php`). One property per part, switched by width, so a phone never fetches the
 * desktop file:
 *
 * - each an `image-set()` of the file's AVIF and WebP sizes, **Zoom (2400) for a desktop band, Detail (1200) for a
 *   phone band and for the edges**; never the original (handoff §5.5);
 * - **transparent parts WebP only** (part 11 here; part 6 is drawn as a picture): Platform's JPEG sizes turn
 *   transparency white, and AVIF's handling on GD is not verified;
 * - **part 11, the page's edges** (`--tw-backdrop`): the start side's image on the start side, written for the page's
 *   direction since `background-position` has no start or end; tiled down from each side of the content column
 *   (72rem, `max-w-6xl`), so it never sits behind it; **hidden at 1024 px and under** `[ACCEPTED 2026-11-10]`;
 * - **one set of artwork for light and dark** (Q8 (a)): in dark the bands' ground is softened by a fixed tint, half the
 *   existing `--tw-overlay` token of that mode (the build coordinator's pick K8: no new colour).
 *
 * An address is written only when it is a plain `https://`, `http://` or root-relative URL with none of `"'()\<>` or
 * white space in it, so nothing can close the property, the rule or the `<style>` element.
 */
final class ThemeStyle
{
    /** The background parts and their property, in §1.8's order. */
    private const array BANDS = [
        'ANNOUNCEMENT' => '--tw-theme-bar',
        'PROMISES' => '--tw-theme-promises',
        'POINTS' => '--tw-theme-points',
        'LOGOS' => '--tw-theme-logos',
        'FOOTER' => '--tw-theme-footer',
    ];

    /** The content column's half width (`max-w-6xl`, 72rem), which the edges' artwork stops at. */
    private const string HALF_COLUMN = '36rem';

    private const string TINT = 'linear-gradient(color-mix(in oklab, var(--tw-overlay) 50%, transparent), color-mix(in oklab, var(--tw-overlay) 50%, transparent))';

    /**
     * @param  array<string, array{images: array<string, array<string, array<string, string>>>}>  $parts  the live theme's parts, by `ThemePartKind` value
     */
    public static function of(array $parts, string $direction): string
    {
        $phone = [];
        $wide = [];
        $dark = [];
        $darkWide = [];

        foreach (self::BANDS as $part => $property) {
            $images = $parts[$part]['images'] ?? [];
            // The announcement strip has its own phone image; a band one image, shown at both widths.
            $small = self::imageSet($images[$part === ThemePartKind::Announcement->value ? 'PHONE:any' : 'BAND:any'] ?? [], MediaSize::Detail, false);
            $large = self::imageSet($images[$part === ThemePartKind::Announcement->value ? 'DESKTOP:any' : 'BAND:any'] ?? [], MediaSize::Zoom, false);

            if ($small === null && $large === null) {
                continue;
            }

            $small ??= $large;
            $large ??= $small;
            $phone[] = "{$property}: {$small};";
            $wide[] = "{$property}: {$large};";
            $dark[] = "{$property}: ".self::TINT.", {$small};";
            $darkWide[] = "{$property}: ".self::TINT.", {$large};";
        }

        $edges = self::edges($parts[ThemePartKind::Backdrop->value]['images'] ?? [], $direction);
        $css = '';

        if ($phone !== []) {
            $css .= 'html[data-mode] { '.implode(' ', $phone)." }\n";
            $css .= "html[data-mode='dark'] { ".implode(' ', $dark)." }\n";
            $css .= '@media (min-width: 641px) { html[data-mode] { '.implode(' ', $wide)." } html[data-mode='dark'] { ".implode(' ', $darkWide)." } }\n";
        }

        if ($edges !== null) {
            $css .= "@media (min-width: 1025px) { html[data-mode] { --tw-backdrop: {$edges}; } }\n";
        }

        return $css;
    }

    /**
     * Part 11's two images, each on its side of the column: on a right-to-left page the start side is the right.
     *
     * @param  array<string, array<string, array<string, string>>>  $images
     */
    private static function edges(array $images, string $direction): ?string
    {
        $start = self::imageSet($images['START:any'] ?? [], MediaSize::Detail, true);
        $end = self::imageSet($images['END:any'] ?? [], MediaSize::Detail, true);

        if ($start === null && $end === null) {
            return null;
        }

        // A 480 px tile whose inner edge meets the column's edge. A percentage in `background-position` is of the page
        // less the image (480 px), hence the 240 px: the right one's left edge lands at 50% + 36rem, the left one's
        // right edge at 50% - 36rem. Wider than the gap, the outer side is cut by the page's edge.
        $right = 'left calc(50% + '.self::HALF_COLUMN.' + 240px) top / 480px auto repeat-y';
        $left = 'left calc(50% - '.self::HALF_COLUMN.' - 240px) top / 480px auto repeat-y';
        [$startAt, $endAt] = $direction === 'rtl' ? [$right, $left] : [$left, $right];
        $layers = [];

        if ($start !== null) {
            $layers[] = "{$start} {$startAt}";
        }

        if ($end !== null) {
            $layers[] = "{$end} {$endAt}";
        }

        $layers[] = 'var(--tw-page)';

        return implode(', ', $layers);
    }

    /**
     * An `image-set()` of one size's AVIF and WebP (WebP only for a transparent part), or null when neither is ready
     * or safe to write.
     *
     * @param  array<string, array<string, string>>  $variants  size slug => format => address
     */
    private static function imageSet(array $variants, MediaSize $size, bool $transparent): ?string
    {
        $formats = $variants[$size->slug()] ?? null;

        if (! is_array($formats)) {
            return null;
        }

        $entries = [];

        foreach ($transparent ? ['webp'] : ['avif', 'webp'] as $format) {
            $url = $formats[$format] ?? null;

            if (is_string($url) && self::safe($url)) {
                $entries[] = "url(\"{$url}\") type(\"image/{$format}\")";
            }
        }

        return $entries === [] ? null : 'image-set('.implode(', ', $entries).')';
    }

    /** A plain address that cannot leave the `url("…")` it is written in. */
    public static function safe(string $url): bool
    {
        return preg_match('~^(https?://[^\s"\'()\\\\<>]+|/[^/\s"\'()\\\\<>][^\s"\'()\\\\<>]*)$~', $url) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Documents;

/**
 * Whether a link typed by staff names a page of the shop (content.md §1.1 rule 16), however it was written: as the
 * path (`/pages/guide`), or in full on the shop's own host with the store and language before it
 * (`https://{host}/sa/ar/pages/guide`). Both sides are brought to one spelling first (PR 3 review):
 *
 * - the part after `#` or `?` dropped - a link to part of a page names the page;
 * - the scheme and host lower-cased, an explicit `:443` dropped, `www.` before the shop's host read as the host;
 * - the path percent-decoded, so `%d8%a7` and `%D8%A7` and the letter itself are one;
 * - one trailing slash dropped.
 */
final readonly class LinkMatch
{
    /** @var array<string, true> */
    private array $names;

    /**
     * @param  list<string>  $paths  the pages' paths, under the store and language (`/pages/guide`)
     * @param  string  $host  the shop's host, as APP_URL gives it; empty: full addresses are not matched
     */
    public function __construct(array $paths, string $storeCode, string $host)
    {
        $names = [];
        $host = self::host($host);

        foreach ($paths as $path) {
            $names[self::normalise($path)] = true;

            foreach ($host === '' ? [] : ['ar', 'en'] as $locale) {
                $names[self::normalise("https://{$host}/{$storeCode}/{$locale}{$path}")] = true;
            }
        }

        $this->names = $names;
    }

    public function names(string $link): bool
    {
        return isset($this->names[self::normalise($link)]);
    }

    /** One spelling of a link: see the class. */
    public static function normalise(string $link): string
    {
        $link = (string) preg_replace('/[#?].*\z/s', '', trim($link));

        if (preg_match('#\Ahttps://([^/]*)(.*)\z#is', $link, $parts) === 1) {
            return 'https://'.self::host($parts[1]).self::path($parts[2]);
        }

        return self::path($link);
    }

    private static function host(string $host): string
    {
        $host = strtolower($host);
        $host = (string) preg_replace('/:443\z/', '', $host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function path(string $path): string
    {
        $path = rawurldecode($path);

        return strlen($path) > 1 && str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
    }
}

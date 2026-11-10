<?php

declare(strict_types=1);

namespace Modules\Content\Domain\ValueObject;

use Shared\Domain\Text\LatinDigits;

/**
 * An address as the redirects keep and compare it (content.md §1.15): **a path on the domain, with no host:
 * decoded, its trailing slash trimmed, its query string and fragment dropped; compared exactly**. Digits are kept
 * as 0-9 (§1.1 rule 3), and a visitor's address is read the same way before it is looked up, so a line typed with
 * Arabic-Indic digits still matches it.
 *
 * Null for what can never be asked about (`InMemoryUnmatchedAddresses::pathOf`): not UTF-8 once decoded, or holding
 * a control character or a backslash - and for an empty path or the front page itself.
 */
final class RedirectPath
{
    public const int MAX = 2000;

    /**
     * @param  string|null  $ownHost  this site's host: an address written whole on it, `https://{host}/old`, or on its
     *                                `www.` twin, is read as its path (a sheet exported from Search Console names them
     *                                so); any other host is no path of ours
     */
    public static function of(?string $typed, ?string $ownHost = null): ?string
    {
        $path = trim((string) $typed);

        if (preg_match('#\Ahttps?://([^/?\#]+)(.*)\z#i', $path, $whole) === 1) {
            $host = strtolower($whole[1]);

            if ($ownHost === null || ($host !== strtolower($ownHost) && $host !== 'www.'.strtolower($ownHost))) {
                return null;
            }

            $path = $whole[2] === '' ? '/' : $whole[2];
        }

        $path = preg_split('/[?#]/', $path, 2)[0] ?? '';

        // A path typed without its first slash (`contact`) is read with it; one typed with two is another site's.
        return self::asked(rawurldecode(str_starts_with($path, '/') ? $path : '/'.$path));
    }

    /**
     * An address as a visitor asked for it, already decoded (`UnmatchedAddress::answer`'s path), read the way a line
     * is kept: never decoded twice, so `/a%2520b` stays the `/a%20b` it was asked as.
     */
    public static function asked(string $path): ?string
    {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('//u', $path) !== 1 || preg_match('/[\p{Cc}\\\\]/u', $path) === 1) {
            return null;
        }

        $path = LatinDigits::of(rtrim($path, '/'));

        return $path === '' || mb_strlen($path) > self::MAX ? null : $path;
    }

    /** @return list<string> the path's words, without the empty one before the first slash */
    public static function words(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $word): bool => $word !== ''));
    }

    /** The path as a Location header carries it: each word percent-encoded, the slashes kept. */
    public static function encoded(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}

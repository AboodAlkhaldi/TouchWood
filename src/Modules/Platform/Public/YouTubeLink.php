<?php

declare(strict_types=1);

namespace Modules\Platform\Public;

/**
 * A YouTube link pasted by staff (flags-2026-11.md S4.4): what Catalog's product videos and Content's project videos
 * both read, so the two never disagree about what a link is. **Only the video's 11-character id is kept**; the link
 * itself is not.
 *
 * Taken: `youtube.com/watch?v=<id>` (with `www.` or `m.`, and any other parameters such as `&t=30s`), `youtu.be/<id>`,
 * `youtube.com/shorts/<id>`, `youtube.com/embed/<id>` and `youtube-nocookie.com/embed/<id>` - over https, http or
 * with no scheme typed. Anything else answers null, for the module to refuse with "Paste a YouTube link.": another
 * site, a playlist or a channel, a link with a name and password or a port, a space inside, an id not of 11
 * characters of `A-Z a-z 0-9 _ -`.
 */
final class YouTubeLink
{
    /** Longer than any link YouTube gives, so a page of pasted text is not read through. */
    public const int MAX = 2000;

    private const string ID = '/\A[A-Za-z0-9_-]{11}\z/';

    private const array WATCH_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];

    private const array EMBED_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    /** The video's id, or null when the text is not a link to one video on YouTube. */
    public static function id(string $pasted): ?string
    {
        $link = trim($pasted);

        if ($link === '' || strlen($link) > self::MAX || preg_match('/[\s\p{Cc}]/u', $link) !== 0) {
            return null;
        }

        if (preg_match('#\A[a-z][a-z0-9+.-]*://#i', $link) !== 1) {
            $link = 'https://'.$link;
        }

        $parts = parse_url($link);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $path = rtrim($parts['path'] ?? '', '/');

        if (! in_array($scheme, ['https', 'http'], true)) {
            return null;
        }

        $id = match (true) {
            $host === 'youtu.be' => substr($path, 1),
            in_array($host, self::WATCH_HOSTS, true) && $path === '/watch' => self::watched($parts['query'] ?? ''),
            in_array($host, self::WATCH_HOSTS, true) && str_starts_with($path, '/shorts/') => substr($path, 8),
            in_array($host, self::EMBED_HOSTS, true) && str_starts_with($path, '/embed/') => substr($path, 7),
            default => null,
        };

        return $id !== null && self::isId($id) ? $id : null;
    }

    /** Whether the text is a video id as YouTube writes one: 11 characters of `A-Z a-z 0-9 _ -`. */
    public static function isId(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    /** The `v` of a watch link's parameters, when there is exactly one. */
    private static function watched(string $query): ?string
    {
        $found = null;

        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if ($name === 'v') {
                if ($found !== null) {
                    return null;
                }

                $found = $value;
            }
        }

        return $found;
    }
}

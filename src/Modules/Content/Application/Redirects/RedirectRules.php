<?php

declare(strict_types=1);

namespace Modules\Content\Application\Redirects;

use Illuminate\Contracts\Config\Repository as Config;
use Modules\Content\Domain\Exception\RedirectInvalid;
use Modules\Content\Domain\ValueObject\RedirectPath;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\ReservedPaths;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * What a line of the redirects may hold (content.md §1.15, §7 `RedirectInvalid`), checked before anything is written
 * and before the table's CHECKs could see it:
 *
 * - **The old address** is a path on the domain (`RedirectPath`). **One beginning with a store's code is refused,
 *   an off store's included** - the new site answers there, or will when the store opens, and an off store's code
 *   reads as "no store", which would otherwise send its visitors on (PR 2's review). **So is one beginning with a
 *   reserved path** (`/admin`, `/build`...), which is never asked about, and the two files the site writes itself.
 *   A single word is allowed (`/blog`, `/contact`).
 * - **The new address** is a full shop path, `/{store}/{locale}/...`, on a store that exists, on or off (an off one
 *   is marked by Check Addresses). **It can't be a chain**: an old address never begins with a store's code and a
 *   new one must. No query string: a line names a page.
 *
 * Every store is read once, from Platform's cached directory (`allStores`).
 */
final readonly class RedirectRules
{
    /** @var list<string> the site's own files, answered by their routes: never an old address */
    private const array OWN_FILES = ['robots.txt', 'sitemap.xml'];

    public function __construct(
        private PlatformApi $platform,
        private ReservedPaths $reserved,
        private Config $config,
    ) {}

    /**
     * The old address as kept, or refused.
     *
     * @throws RedirectInvalid
     */
    public function oldPath(?string $typed): string
    {
        $path = RedirectPath::of($typed, $this->ownHost()) ?? throw new RedirectInvalid(RedirectInvalid::OLD_PATH);
        $first = RedirectPath::words($path)[0] ?? '';

        if ($this->storeOf($first) !== null) {
            throw new RedirectInvalid(RedirectInvalid::OLD_STORE);
        }

        if ($this->reserved->isReserved(strtolower($first)) || in_array(strtolower($first), self::OWN_FILES, true) || preg_match('/\Asitemap-\d+\.xml\z/i', $first) === 1) {
            throw new RedirectInvalid(RedirectInvalid::OLD_RESERVED);
        }

        return $path;
    }

    /**
     * The new address as kept, or refused.
     *
     * @throws RedirectInvalid
     */
    public function newPath(?string $typed): string
    {
        $typed = trim((string) $typed);

        if (str_contains($typed, '?') || str_contains($typed, '#')) {
            throw new RedirectInvalid(RedirectInvalid::NEW_PATH);
        }

        $path = RedirectPath::of($typed, $this->ownHost()) ?? throw new RedirectInvalid(RedirectInvalid::NEW_PATH);
        $words = RedirectPath::words($path);

        if (count($words) < 2 || preg_match('/\A[a-z]{2,8}\z/', $words[0]) !== 1 || ! in_array($words[1], ['ar', 'en'], true)) {
            throw new RedirectInvalid(RedirectInvalid::NEW_PATH);
        }

        if ($this->storeOf($words[0]) === null) {
            throw new RedirectInvalid(RedirectInvalid::NEW_STORE);
        }

        return $path;
    }

    /**
     * Both, the old one first (a line is refused for its old address before its new one).
     *
     * @return array{0: string, 1: string}
     *
     * @throws RedirectInvalid
     */
    public function line(?string $old, ?string $new): array
    {
        return [$this->oldPath($old), $this->newPath($new)];
    }

    /** The store a first word names, on or off, or null. */
    public function storeOf(string $word): ?StoreDto
    {
        foreach ($this->platform->allStores() as $store) {
            if ($store->code === $word) {
                return $store;
            }
        }

        return null;
    }

    /** This site's host, as APP_URL names it without "www.". */
    public function ownHost(): ?string
    {
        $url = $this->config->get('app.url');
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}

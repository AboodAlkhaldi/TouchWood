<?php

declare(strict_types=1);

namespace Modules\Content\Application\Sitemap;

use Modules\Content\Domain\Repository\StoreSeo;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * **One generated `/robots.txt`, with each on store's lines** (content.md §1.14): crawlers read one robots file a
 * domain. Each store's own addresses allowed, and Disallowed in both languages: its cart, checkout, account and
 * search, and the paths typed for it on its SEO screen; `/admin` for the whole site; and the sitemap index. A store
 * that is off is not in it. **Anywhere but production it says Disallow: /** (§9.3 #28), so staging is never indexed.
 *
 * Two reads, both small: the stores from Platform's cache, every store's typed lines in one query.
 */
final readonly class RobotsFile
{
    /** @var list<string> the shop paths never indexed, under each store and language */
    public const array DISALLOWED = ['/cart', '/checkout', '/account', '/search'];

    public function __construct(
        private PlatformApi $platform,
        private StoreSeo $seo,
    ) {}

    /**
     * @param  string  $root  the site's root, `https://host`, with no trailing slash
     */
    public function text(bool $production, string $root): string
    {
        if (! $production) {
            return "User-agent: *\nDisallow: /\n";
        }

        $typed = $this->seo->robotsLines();
        $lines = ['User-agent: *', 'Disallow: /admin'];

        foreach ($this->platform->stores() as $store) {
            $lines[] = "Allow: /{$store->code}/";

            foreach (['ar', 'en'] as $locale) {
                foreach ([...self::DISALLOWED, ...($typed[$store->id] ?? [])] as $path) {
                    $lines[] = "Disallow: /{$store->code}/{$locale}".self::ascii($path);
                }
            }
        }

        $lines[] = '';
        $lines[] = "Sitemap: {$root}/sitemap.xml";

        return implode("\n", $lines)."\n";
    }

    /**
     * A typed path as a crawler matches it: each byte outside ASCII percent-encoded, as a browser sends an Arabic
     * address; `*` and `$`, the patterns crawlers read, kept as typed.
     */
    private static function ascii(string $path): string
    {
        return (string) preg_replace_callback('/[\x80-\xFF]/', static fn (array $byte): string => '%'.strtoupper(bin2hex($byte[0])), $path);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http;

use Illuminate\Http\Request;
use LogicException;

/**
 * The storefront's languages (owner's decision, 2026-09-18): the language is the second URL
 * segment, brand.com/sa/ar/... — Google needs a separate address per language. Without one, a
 * visitor gets the language they used last (remembered in a cookie), otherwise the default,
 * Arabic, for every store.
 */
final readonly class StorefrontLanguage
{
    public const string COOKIE = 'tw_locale';

    /**
     * @param  list<string>  $supported  e.g. ['ar', 'en'] — every name in the system has both
     * @param  string  $default  used when the visitor has no remembered language
     */
    public function __construct(
        private array $supported,
        private string $default,
    ) {
        if ($supported === [] || ! in_array($default, $supported, true)) {
            throw new LogicException("The default language \"{$default}\" must be one of the supported languages.");
        }

        foreach ($supported as $locale) {
            if (preg_match('/\A[a-z]{2}\z/', $locale) !== 1) {
                throw new LogicException("\"{$locale}\" is not a two-letter language code.");
            }
        }
    }

    /**
     * Every language the shop can be read in, for the switch in its header (frontend.md 2.3).
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->supported;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported, true);
    }

    /**
     * The visitor's remembered language if it is still supported, otherwise the default.
     */
    public function for(Request $request): string
    {
        $remembered = $request->cookie(self::COOKIE);

        return is_string($remembered) && $this->isSupported($remembered) ? $remembered : $this->default;
    }

    /**
     * The route requirement for {locale}.
     */
    public function routePattern(): string
    {
        return implode('|', $this->supported);
    }
}

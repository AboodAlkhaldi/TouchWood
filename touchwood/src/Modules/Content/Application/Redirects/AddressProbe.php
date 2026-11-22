<?php

declare(strict_types=1);

namespace Modules\Content\Application\Redirects;

/**
 * Asks the shop what it answers at one of its own addresses, as a visitor would and without following a redirect
 * (content.md §1.15's Check Addresses). Only ever this site's own paths, on its own host (`APP_URL`).
 */
interface AddressProbe
{
    /**
     * @param  string  $path  a shop path as the redirects keep it: decoded, `/sa/ar/products/x`
     * @return array{status: int, location: string|null}|null the answer's status and, for a redirect to a page of
     *                                                        this site, that page's path, decoded; null when no
     *                                                        answer came (the site unreachable, a time-out)
     */
    public function answer(string $path): ?array;
}

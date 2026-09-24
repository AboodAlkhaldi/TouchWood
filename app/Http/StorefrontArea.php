<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The shop as a place a module can put a page (frontend.md §2.3).
 *
 * The other half of {@see AdminArea}, and named here for the same reason: the shop is one area with
 * one door, **Access implements most of it** - the session, who is here, what travels with the page
 * - and **Platform implements the rest**, because which store a URL belongs to is Platform's
 * subject. Neither may reach into the other for it, and the modules that come later will put their
 * own pages here: Catalog's product pages, Sales's basket and checkout.
 *
 * Every shop page lives under /{store}/{locale}, except the country page at / which is what somebody
 * with no store yet is given.
 */
final class StorefrontArea
{
    /** The shop's own session cookie, set before `web` opens the session. */
    public const string SESSION = 'access.storefront-session';

    /** Which store this URL belongs to, and the language it is read in. */
    public const string STORE = 'store';

    /** The store and the language as the page reads them - Platform's half of a shop page. */
    public const string SHOP = 'storefront.shop';

    /** Who is here: the customer signed in, or nobody, in which case a guest. */
    public const string IDENTIFY = 'access.identify-customer';

    /** What travels with every page of the shop: the store, the languages, whoever is signed in. */
    public const string PAGE = 'storefront.page';

    /** And this on top for anything only a signed-in customer may reach. */
    public const string SIGNED_IN = 'access.customer';

    /**
     * The words the shop's frame itself reads, which every page of it must carry.
     *
     * A page names the translation files it uses and gets those and nothing else (frontend.md
     * §1.5) - **including its layout's**, because the layout has no way to ask for them. The
     * header holds a country switch, a theme toggle and either a name or the way in, and a page
     * that ships its own words but not these renders "access::auth.sign_out" on the screen, where
     * somebody reads it (found by running it, 2026-09-24). Named here so a module adding a shop
     * page spreads one list rather than remembering three.
     *
     * @var list<string>
     */
    public const array WORDS = ['access::auth', 'platform::stores', 'admin'];

    /**
     * What every shop page under a store passes through, signed in or not, in this order.
     *
     * @var list<string>
     */
    public const array MIDDLEWARE = [self::SESSION, 'web', self::STORE, self::SHOP, self::IDENTIFY, self::PAGE];

    /**
     * And the same without resolving a store, for the country page.
     *
     * That page is the one part of the shop that belongs to no store - it exists because the
     * visitor has not chosen one - so asking which store its URL names answers nothing, and the
     * middleware that asks refuses the page outright (found by running it, 2026-09-24).
     *
     * @var list<string>
     */
    public const array MIDDLEWARE_WITHOUT_STORE = [self::SESSION, 'web', self::SHOP, self::IDENTIFY, self::PAGE];
}

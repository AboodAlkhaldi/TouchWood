<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http;

/**
 * The customer account's own tabs (frontend.md F8), in the order they are shown; the first is the
 * one anybody arriving without asking gets.
 *
 * One list for the account page, which opens them, and for every shop page, which shares them so
 * another module's account page can link back to them (access.md amendment 50). Each is named at
 * `access::account.shop_tab.{tab}`.
 */
final class CustomerAccountTabs
{
    /** @var list<string> */
    public const array ALL = ['profile', 'security', 'phone', 'addresses', 'close'];
}

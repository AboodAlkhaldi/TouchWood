<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\StorefrontArea;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Access\Application\Query\CustomerReader;
use Modules\Access\Application\Session\CustomerSessions;
use Modules\Access\Presentation\Http\CustomerAccountTabs;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Contracts\ShopperLines;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Dto\ShopperLineDto;
use Modules\Access\Public\Enums\AccountType;
use Symfony\Component\HttpFoundation\Response;
use Tighten\Ziggy\Ziggy;

/**
 * Who is in the shop, on every page of it (frontend.md §2.3).
 *
 * The admin's counterpart is {@see ShareAdminPage}, and this is deliberately smaller. A shop page
 * carries whoever is signed in, the shop's own addresses, and what other modules add to the frame
 * for them (access.md amendment 50): the pages beside the account's own tabs, and the lines under
 * the header. There is no menu, because the shop's navigation is its catalogue and that is
 * Catalog's to build; there is no permission anywhere, because a shopper holds none.
 *
 * Which store the page belongs to, and the language it is read in, are **Platform's** to share -
 * they are written in the address, and the address is Platform's subject. Access may not reach
 * into Platform for them, and does not need to.
 *
 * **Only the storefront's URLs travel with a shop page** (§1.4), so a shopper's page never carries
 * the panel's addresses - the same rule the panel follows in reverse.
 *
 * Every value here is a closure: a page that never reads the customer costs no query for one, and
 * the three that do read it once between them.
 */
final readonly class ShareStorefrontPage
{
    public const string ALIAS = StorefrontArea::PAGE;

    public function __construct(
        private CustomerSessions $sessions,
        private CustomerReader $customers,
        private CustomerAccountPages $accountPages,
        private ShopperLines $lines,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Read at most once per request, by whichever closure asks first. `false` is "not read yet":
        // null is a real answer - nobody is signed in.
        $read = false;
        $customer = function () use (&$read): ?array {
            if ($read === false) {
                $read = $this->customer();
            }

            return $read;
        };

        Inertia::share([
            'shopper' => fn (): ?array => self::shopper($customer()),
            'accountMenu' => fn (): ?array => $this->accountMenu($customer()),
            'shopperLines' => fn (): array => $this->shopperLines($customer()),
            // The shop's own group, never the panel's (§1.4). It travels with the page because
            // Blade's @routes never reaches the server renderer.
            'routes' => fn (): array => (new Ziggy(group: 'storefront'))->toArray(),
        ]);

        return $next($request);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customer(): ?array
    {
        $customerId = $this->sessions->signedIn();

        return $customerId === null ? null : $this->customers->customer($customerId);
    }

    /**
     * Whoever is signed in, as the header names them - and nothing more.
     *
     * A shop page has no business carrying an address, a phone number or an order history around
     * with it; the account screens ask for what they show, when they show it.
     *
     * @param  array<string, mixed>|null  $customer
     * @return array<string, mixed>|null
     */
    private static function shopper(?array $customer): ?array
    {
        if ($customer === null) {
            return null;
        }

        return [
            'id' => $customer['id'],
            'name' => trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')),
            // The reader answers 'email_verified', a boolean - asking it for a timestamp gets
            // null every time, which reads as "never confirmed" for everybody (found by a test
            // that asserted false and passed for the wrong reason, 2026-09-24).
            'emailVerified' => ($customer['email_verified'] ?? false) === true,
        ];
    }

    /**
     * The account's side list: its own tabs, and the pages other modules add for this kind of
     * account (amendment 50) - B2B's company page for a company account. Nobody signed in has none.
     *
     * @param  array<string, mixed>|null  $customer
     * @return array{tabs: list<array{key: string, label: string}>, pages: list<array{key: string, label: string, routeName: string}>}|null
     */
    private function accountMenu(?array $customer): ?array
    {
        $type = self::accountType($customer);

        if ($type === null) {
            return null;
        }

        return [
            'tabs' => array_map(
                static fn (string $tab): array => ['key' => $tab, 'label' => self::words("access::account.shop_tab.{$tab}")],
                CustomerAccountTabs::ALL,
            ),
            'pages' => array_map(
                static fn (CustomerAccountPageDto $page): array => [
                    'key' => $page->name(),
                    'label' => self::words($page->labelKey()),
                    'routeName' => $page->routeName,
                ],
                $this->accountPages->for($type),
            ),
        ];
    }

    /**
     * What other modules have to say to this customer under the header (amendment 50).
     *
     * @param  array<string, mixed>|null  $customer
     * @return list<array{text: string, routeName: string, tone: string}>
     */
    private function shopperLines(?array $customer): array
    {
        $type = self::accountType($customer);

        if ($customer === null || $type === null) {
            return [];
        }

        return array_map(
            static fn (ShopperLineDto $line): array => ['text' => $line->text, 'routeName' => $line->routeName, 'tone' => $line->tone->value],
            $this->lines->for((string) $customer['id'], $type, ($customer['email_verified'] ?? false) === true),
        );
    }

    /**
     * @param  array<string, mixed>|null  $customer
     */
    private static function accountType(?array $customer): ?AccountType
    {
        return $customer === null ? null : AccountType::tryFrom((string) ($customer['account_type'] ?? ''));
    }

    private static function words(string $key): string
    {
        $words = __($key);

        return is_string($words) ? $words : $key;
    }
}

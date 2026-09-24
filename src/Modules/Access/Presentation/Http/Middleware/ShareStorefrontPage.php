<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\StorefrontArea;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Access\Application\Query\CustomerReader;
use Modules\Access\Application\Session\CustomerSessions;
use Symfony\Component\HttpFoundation\Response;
use Tighten\Ziggy\Ziggy;

/**
 * Who is in the shop, on every page of it (frontend.md §2.3).
 *
 * The admin's counterpart is {@see ShareAdminPage}, and this is deliberately smaller. A shop page
 * carries whoever is signed in and the shop's own addresses, and nothing else. There is no menu,
 * because the shop's navigation is its catalogue and that is Catalog's to build; there is no
 * permission anywhere, because a shopper holds none.
 *
 * Which store the page belongs to, and the language it is read in, are **Platform's** to share -
 * they are written in the address, and the address is Platform's subject. Access may not reach
 * into Platform for them, and does not need to.
 *
 * **Only the storefront's URLs travel with a shop page** (§1.4), so a shopper's page never carries
 * the panel's addresses - the same rule the panel follows in reverse.
 *
 * Every value here is a closure: a page that never reads the customer costs no query for one.
 */
final readonly class ShareStorefrontPage
{
    public const string ALIAS = StorefrontArea::PAGE;

    public function __construct(
        private CustomerSessions $sessions,
        private CustomerReader $customers,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share([
            'shopper' => fn (): ?array => $this->shopper(),
            // The shop's own group, never the panel's (§1.4). It travels with the page because
            // Blade's @routes never reaches the server renderer.
            'routes' => fn (): array => (new Ziggy(group: 'storefront'))->toArray(),
        ]);

        return $next($request);
    }

    /**
     * Whoever is signed in, as the header names them - and nothing more.
     *
     * A shop page has no business carrying an address, a phone number or an order history around
     * with it; the account screens ask for what they show, when they show it.
     *
     * @return array<string, mixed>|null
     */
    private function shopper(): ?array
    {
        $customerId = $this->sessions->signedIn();

        if ($customerId === null) {
            return null;
        }

        $customer = $this->customers->customer($customerId);

        if ($customer === null) {
            return null;
        }

        return [
            'id' => $customerId,
            'name' => trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')),
            'emailVerified' => ($customer['email_verified_at'] ?? null) !== null,
        ];
    }
}

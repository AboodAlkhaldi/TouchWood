<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AdminArea;
use App\Http\StorefrontArea;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Symfony\Component\HttpFoundation\Response;

/**
 * No browser copy of a page that shows somebody's data (handoff §15.4; owner 2026-10-29): every admin
 * page, a shop page seen by a signed-in customer, and one a staff member looks at from the panel (it may
 * be a store that is off). A guest's shop page holds nobody's data, so it keeps the browser's instant Back.
 *
 * **The browser's history is a copy too (owner, 2026-11-03; frontend.md §1.12).** Inertia keeps each page's
 * props in its history entry, where `no-store` does not reach, and Back draws them with no request. So a page
 * for someone signed in is kept there encrypted, under a key in that tab's sessionStorage, and a page for
 * nobody throws the key away: whichever way the session ended - Sign Out, the idle limit, everywhere, from
 * another device - the first page answered without it makes the older entries unreadable, and Back asks the
 * server instead.
 *
 * It sits right after the area's "who is here" in its middleware list, inside it: a test clears the
 * actor once that middleware returns, so anything further out would see the system instead.
 */
final readonly class KeepNoCopy
{
    public const string ALIAS = 'keep-no-copy';

    /** The addresses a browser trusts without HTTPS: anywhere else on plain http it has no crypto at all. */
    private const array LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    public function __construct(private ActorContext $actors) {}

    public function handle(Request $request, Closure $next): Response
    {
        $someone = in_array($this->actors->current()->type, [ActorType::Staff, ActorType::Customer], true)
            || $request->attributes->get(StorefrontArea::STAFF_VIEW) === true;

        if ($someone) {
            Inertia::encryptHistory($this->browserCanEncrypt($request));
        } elseif ($request->isMethod('GET')) {
            Inertia::clearHistory();
        }

        /** @var Response $response */
        $response = $next($request);

        // Inertia's switch lasts as long as the process, and the page is made by now.
        Inertia::encryptHistory(false);

        if ($request->is(AdminArea::PREFIX, AdminArea::PREFIX.'/*')
            || $this->actors->current()->type === ActorType::Customer
            || $request->attributes->get(StorefrontArea::STAFF_VIEW) === true) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * Whether the browser that asked has `crypto.subtle`, which only a secure context has. Without it Inertia
     * throws while writing the page to the history and the page never draws, so plain http keeps the panel
     * working with no encryption, and Back there still shows what it showed. Behind a proxy `secure()` needs
     * the proxy trusted (handoff §15.4); until then this says no, which loses the encryption and breaks nothing.
     */
    private function browserCanEncrypt(Request $request): bool
    {
        $host = $request->getHost();

        return $request->secure() || in_array($host, self::LOOPBACK, true) || str_ends_with($host, '.localhost');
    }
}

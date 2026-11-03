<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Inertia\Response as InertiaResponse;
use ReflectionMethod;

/**
 * Where a signed-out staff member was going (amendment 95; owner, 2026-11-02): an alert's link opens the page once
 * they have signed in.
 *
 * Every way through signing in ends at the panel's home, so that is the one place it is used, once: the password, the
 * SMS or app code, a new phone, a trusted browser and a forced setup all pass it on by leaving it in the session.
 *
 * Only an address the panel's own request gave, and only a page of the panel: a path under /admin with its query, never
 * a full address, another host, or a sign-in, sign-out, password or invitation address. It is read back through the same
 * check. It lives in the panel's own session (UseAdminSession), so a customer's `url.intended` never meets it.
 *
 * Only a route that answers a page: its action declares an Inertia response. A file would leave the sign-in form on
 * screen after it downloaded, and JSON would show as text; a new route of either kind is left out with nothing to list.
 *
 * It lasts 30 minutes, so neither a stale address nor another person's on a shared computer is used hours later. Within
 * them it cannot tell one person from another, and whoever signs in still needs permission for the page.
 */
final class StaffReturnAddress
{
    private const string KEY = 'access.staff_return';

    /** Longer than any address of the panel: never kept in the session. */
    private const int MOST = 2000;

    private const string HOME = '/admin';

    /** How long an address lasts, in seconds. */
    private const int LASTS = 1800;

    /** @var list<string> */
    private const array NEVER = ['/admin/sign-in', '/admin/sign-out', '/admin/password', '/admin/invitation', '/admin/email-change'];

    /** Remembers a visit to a page, the latest over any before it; asking for home itself forgets what was remembered. */
    public static function remember(Request $request): void
    {
        if (! $request->isMethod('GET') || ! $request->hasSession()) {
            return;
        }

        $address = $request->getRequestUri();

        if (self::isHome($address)) {
            $request->session()->forget(self::KEY);
        } elseif (self::isSafe($address) && self::answersPage($request->route())) {
            $request->session()->put(self::KEY, ['address' => $address, 'at' => CarbonImmutable::now()->getTimestamp()]);
        }
    }

    /** The address remembered, forgotten as it is read; null for none, or one past its time. */
    public static function take(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $kept = $request->session()->pull(self::KEY);
        $address = is_array($kept) ? ($kept['address'] ?? null) : null;
        $at = is_array($kept) ? ($kept['at'] ?? null) : null;

        return is_string($address) && is_int($at) && CarbonImmutable::now()->getTimestamp() - $at <= self::LASTS && self::isSafe($address) ? $address : null;
    }

    /** Whether the route's action answers a page: an Inertia response among what it declares. */
    public static function answersPage(mixed $route): bool
    {
        if (! $route instanceof Route) {
            return false;
        }

        $class = $route->getControllerClass();

        if ($class === null) {
            return false;
        }

        $action = $route->getActionMethod();
        $declared = (string) (new ReflectionMethod($class, $action === $class ? '__invoke' : $action))->getReturnType();

        return in_array(InertiaResponse::class, explode('|', $declared), true);
    }

    public static function isSafe(string $address): bool
    {
        if (! str_starts_with($address, '/') || strlen($address) > self::MOST || preg_match('/[\x00-\x20\x7F\\\\]/', $address) === 1) {
            return false;
        }

        $path = self::path($address);

        if (self::isHome($address) || ! str_starts_with($path, self::HOME.'/') || str_contains($path, '//') || preg_match('~/\.{1,2}(/|$)|%(2e|2f|5c)~i', $path) === 1) {
            return false;
        }

        foreach (self::NEVER as $never) {
            if ($path === $never || str_starts_with($path, $never.'/')) {
                return false;
            }
        }

        return true;
    }

    private static function isHome(string $address): bool
    {
        return rtrim(self::path($address), '/') === self::HOME;
    }

    private static function path(string $address): string
    {
        return explode('?', $address, 2)[0];
    }
}

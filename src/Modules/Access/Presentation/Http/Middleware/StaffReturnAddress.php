<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use Illuminate\Http\Request;

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
 */
final class StaffReturnAddress
{
    private const string KEY = 'access.staff_return';

    /** Longer than any address of the panel: never kept in the session. */
    private const int MOST = 2000;

    private const string HOME = '/admin';

    /** @var list<string> */
    private const array NEVER = ['/admin/sign-in', '/admin/sign-out', '/admin/password', '/admin/invitation', '/admin/email-change'];

    /** Remembers a page visit, the latest over any before it; asking for home itself forgets what was remembered. */
    public static function remember(Request $request): void
    {
        if (! $request->isMethod('GET') || ! $request->hasSession()) {
            return;
        }

        $address = $request->getRequestUri();

        if (self::isSafe($address)) {
            $request->session()->put(self::KEY, $address);
        } elseif (strtok($address, '?') === self::HOME) {
            $request->session()->forget(self::KEY);
        }
    }

    /** The address remembered, forgotten as it is read; null for none. */
    public static function take(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $address = $request->session()->pull(self::KEY);

        return is_string($address) && self::isSafe($address) ? $address : null;
    }

    public static function isSafe(string $address): bool
    {
        if (strlen($address) > self::MOST || preg_match('/[\x00-\x20\x7F\\\\]/', $address) === 1) {
            return false;
        }

        $path = (string) strtok($address, '?');

        if (! str_starts_with($path, self::HOME.'/') || str_contains($path, '//') || preg_match('~/\.{1,2}(/|$)|%(2e|2f|5c)~i', $path) === 1) {
            return false;
        }

        foreach (self::NEVER as $never) {
            if ($path === $never || str_starts_with($path, $never.'/')) {
                return false;
            }
        }

        return true;
    }
}

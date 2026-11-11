<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Preview Theme's **signed cookie for this browser** (content.md W5b `[ACCEPTED 2026-11-10]`): which theme its
 * presser sees on the shop, for whom and until when - encrypted with the application's key (an authenticated cipher,
 * so a changed or forged value reads as none), HTTP-only, the whole site's path, two hours (the build's pick). Nobody
 * else sees it: it lives in the presser's browser alone, and it names the presser, so a browser signed in as someone
 * else reads none. **The shop reads it with its frame** (`shopTheme()`, `ShopFrameOfRequest`): past the frame's cache,
 * `noindex`, `no-store`, and only during a staff view of an active Super Admin, asked again on every page.
 *
 * `EncryptCookies` (in `web`, on the panel's routes and the shop's alike) adds and removes its own layer around this
 * one, so what `read()` sees on either side is this class's value.
 */
final readonly class ThemePreviewCookie
{
    public const string NAME = 'tw_theme_preview';

    public const int MINUTES = 120;

    public function __construct(
        private Encrypter $encrypter,
    ) {}

    public function issue(string $themeId, string $staffId): Cookie
    {
        $value = $this->encrypter->encryptString((string) json_encode([
            'purpose' => self::NAME,
            'theme' => strtolower($themeId),
            'staff' => strtolower($staffId),
            'until' => CarbonImmutable::now()->addMinutes(self::MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return Cookie::create(self::NAME, $value, CarbonImmutable::now()->addMinutes(self::MINUTES), '/', null, self::secure(), true, false, Cookie::SAMESITE_LAX);
    }

    public function forget(): Cookie
    {
        return Cookie::create(self::NAME, '', 1, '/', null, self::secure(), true, false, Cookie::SAMESITE_LAX);
    }

    /** Secure as the session's cookie is (`session.secure`, `SESSION_SECURE_COOKIE`), said outright, not left to the request. */
    private static function secure(): bool
    {
        return (bool) config('session.secure', false);
    }

    /** The theme this browser previews for this staff member now, or null: none, another's, past its time, or forged. */
    public function themeFor(Request $request, ?string $staffId): ?string
    {
        $value = $staffId === null ? null : $this->read($request);

        return $value !== null && $value['staff'] === strtolower((string) $staffId) ? $value['theme'] : null;
    }

    /**
     * The theme this browser previews now, on the shop (PR 9), or null: none, past its time, or forged. **The shop
     * knows no staff member by id**, so the caller must still ask that the page is a staff view of someone who may
     * preview (`ShopFrameOfRequest`): the cookie alone shows nothing.
     *
     * On the shop's routes as on the panel's, `EncryptCookies` (in `web`) has taken its own layer off by then; what is
     * read here is this class's own layer.
     */
    public function shopTheme(Request $request): ?string
    {
        return $this->read($request)['theme'] ?? null;
    }

    /** @return array{theme: string, staff: string}|null */
    private function read(Request $request): ?array
    {
        $raw = $request->cookies->get(self::NAME);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $value = json_decode($this->encrypter->decryptString($raw), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($value) || ($value['purpose'] ?? null) !== self::NAME || ! is_string($value['staff'] ?? null) || ! is_string($value['theme'] ?? null)) {
            return null;
        }

        $until = $value['until'] ?? 0;

        return is_int($until) && $until > CarbonImmutable::now()->getTimestamp() ? ['theme' => $value['theme'], 'staff' => $value['staff']] : null;
    }
}

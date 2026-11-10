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
 * else reads none. **The shop reads it with its frame (PR 9)**: past the frame's cache, `noindex`, `no-store`.
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

        return Cookie::create(self::NAME, $value, CarbonImmutable::now()->addMinutes(self::MINUTES), '/', null, null, true, false, Cookie::SAMESITE_LAX);
    }

    public function forget(): Cookie
    {
        return Cookie::create(self::NAME, '', 1, '/', null, null, true, false, Cookie::SAMESITE_LAX);
    }

    /** The theme this browser previews for this staff member now, or null: none, another's, past its time, or forged. */
    public function themeFor(Request $request, ?string $staffId): ?string
    {
        $raw = $request->cookies->get(self::NAME);

        if (! is_string($raw) || $raw === '' || $staffId === null) {
            return null;
        }

        try {
            $value = json_decode($this->encrypter->decryptString($raw), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($value) || ($value['purpose'] ?? null) !== self::NAME || ($value['staff'] ?? null) !== strtolower($staffId)) {
            return null;
        }

        $until = $value['until'] ?? 0;

        return is_int($until) && $until > CarbonImmutable::now()->getTimestamp() && is_string($value['theme'] ?? null) ? $value['theme'] : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Content\Application\Text;

use Illuminate\Cache\RateLimiter;
use Modules\Content\Domain\Exception\TranslationUnavailable;

/**
 * Translate's limits (content.md §1.3, [ACCEPTED 2026-11-10]): **20,000 characters a press** (one call to the
 * service, under its 128 KiB), **30 presses a minute** and **300,000 characters a day per person**, which
 * protect the month's allowance.
 *
 * **One call, under 128 KiB**, checked on the bytes as well (PR 2 review): `deeplcom/deepl-php` writes the
 * texts with `json_encode()`'s default flags, so an Arabic letter goes as `\uXXXX` - six bytes - and a letter
 * outside the basic plane as twelve. 20,000 letters can therefore pass the service's limit, so the texts are
 * encoded as the library encodes them and must stay under `MAX_REQUEST_BYTES`, which leaves 4 KiB for the
 * request's other fields.
 *
 * Counted before the call, so presses sent side by side cannot all pass a limit (lesson 17). A press over
 * the day's limit is taken back off the count, so a refused press costs none of it; a press over the
 * minute's still counts as a press. "A day" is 24 hours from a person's first press counted - the
 * limiter's own window - the build's pick, as the spec names no clock.
 */
final readonly class TranslationLimits
{
    public const int PER_PRESS = 20_000;

    /** DeepL's 128 KiB request, less 4 KiB for its other fields (the languages, the options). */
    public const int MAX_REQUEST_BYTES = 128 * 1024 - 4 * 1024;

    public const int PRESSES_A_MINUTE = 30;

    public const int CHARACTERS_A_DAY = 300_000;

    public function __construct(
        private RateLimiter $limiter,
    ) {}

    /**
     * The characters are the person's words; the bytes are the request really sent, with the tags that
     * carry bold and links and the entities that write `&`, `<` and `>` (§1.3 (a)). Counting the words
     * alone is the build's pick (2026-11-10): the same text passes or not whatever its marks, and the
     * spec's caps name "Arabic characters".
     *
     * @param  list<string>  $pieces  the words one press sends, a text each
     * @param  list<string>|null  $sent  the texts as they go in the request, when not the words themselves
     *
     * @throws TranslationUnavailable over a limit: nothing may be sent
     */
    public function count(string $person, array $pieces, ?array $sent = null): void
    {
        $characters = array_sum(array_map(mb_strlen(...), $pieces));

        if ($characters > self::PER_PRESS || self::requestBytes($sent ?? $pieces) > self::MAX_REQUEST_BYTES) {
            throw new TranslationUnavailable(TranslationUnavailable::TOO_LONG);
        }

        $key = 'content:translate:'.hash('sha256', $person);

        if ($this->limiter->hit($key.':minute', 60) > self::PRESSES_A_MINUTE) {
            throw new TranslationUnavailable(TranslationUnavailable::BUSY);
        }

        if ($this->limiter->increment($key.':day', 86_400, $characters) > self::CHARACTERS_A_DAY) {
            $this->limiter->decrement($key.':day', 86_400, $characters);

            throw new TranslationUnavailable(TranslationUnavailable::DAILY_LIMIT);
        }
    }

    /**
     * The bytes the texts take in the request, encoded as the library encodes them (`json_encode()` with its
     * default flags: every letter past ASCII escaped). A text that will not encode counts as past the limit.
     *
     * @param  list<string>  $pieces
     */
    public static function requestBytes(array $pieces): int
    {
        $encoded = json_encode(['text' => $pieces]);

        return $encoded === false ? PHP_INT_MAX : strlen($encoded);
    }
}

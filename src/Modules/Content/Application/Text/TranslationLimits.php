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
 * Counted before the call, so presses sent side by side cannot all pass a limit (lesson 17). A press over
 * the day's limit is taken back off the count, so a refused press costs none of it; a press over the
 * minute's still counts as a press. "A day" is 24 hours from a person's first press counted - the
 * limiter's own window - the build's pick, as the spec names no clock.
 */
final readonly class TranslationLimits
{
    public const int PER_PRESS = 20_000;

    public const int PRESSES_A_MINUTE = 30;

    public const int CHARACTERS_A_DAY = 300_000;

    public function __construct(
        private RateLimiter $limiter,
    ) {}

    /**
     * @throws TranslationUnavailable over a limit: nothing may be sent
     */
    public function count(string $person, int $characters): void
    {
        if ($characters > self::PER_PRESS) {
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
}

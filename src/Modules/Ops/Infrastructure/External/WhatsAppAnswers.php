<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Http\Client\Response;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * What a refusal from Meta means (ops.md §9.8 point 3), as Brevo's BrevoAnswers does for Brevo: **one person** (that
 * number cannot get this message) is kept apart from **the setup** (a token, a template, the request itself), because
 * only a setup failure tells the Super Admins.
 *
 * Meta's error is JSON, `{"error": {"message", "type", "code", ...}}`; its message is read for nothing and never kept
 * or logged: it may quote a number. **UNVERIFIED:** the numeric codes below are written from memory of Meta's
 * error-code list, which was not reached when this was built (the pages read had no code list); they are one constant
 * each, to be checked on the first real refusals. A 4xx in Meta's own JSON that is not a known per-person code is a
 * setup failure: one that holds up everybody must mend itself, never be thought of as one bad number.
 */
final class WhatsAppAnswers
{
    /** The longest provider id `ops.sent_messages` keeps. */
    public const int MOST_ID = 255;

    /** A 429 with no wait to read. */
    public const int USUAL_WAIT = 60;

    /** UNVERIFIED: 131026 "message undeliverable" (no WhatsApp on that number), 131021 recipient is the sender, 131030 not in the allowed list. */
    private const array ONE_PERSON = [131026, 131021, 131030];

    /** UNVERIFIED: 4 and 80007 "too many calls", 130429 "throughput reached", 131056 "pair rate limit". */
    private const array RATE_LIMITED = [4, 80007, 130429, 131056];

    public static function refused(Response $response): ProviderFailed
    {
        $status = $response->status();
        $code = self::code($response);

        return new ProviderFailed(
            "WhatsApp: Meta refused the message: HTTP {$status}".($code === null ? '' : " (error {$code})").'.',
            self::kind($response, $code),
            self::kind($response, $code) === FailureKind::RateLimited ? self::USUAL_WAIT : null,
        );
    }

    private static function kind(Response $response, ?int $code): FailureKind
    {
        $status = $response->status();

        if ($status === 429 || ($code !== null && in_array($code, self::RATE_LIMITED, true))) {
            return FailureKind::RateLimited;
        }

        // Meta being away, a proxy's page, a timeout: nothing says the setup is wrong.
        if ($status < 400 || $status >= 500 || $status === 408 || $code === null) {
            return FailureKind::MayPass;
        }

        return in_array($code, self::ONE_PERSON, true) ? FailureKind::OneAddress : FailureKind::WaitsForPerson;
    }

    private static function code(Response $response): ?int
    {
        $code = $response->json('error.code');

        return is_int($code) ? $code : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Http\Client\Response;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * What both Brevo adapters read the same way (ops.md §7): the key as pasted, how long to wait, and what a
 * refusal means. As Brevo documents it, read 2026-11-03:
 * - The statuses (https://developers.brevo.com/docs/how-it-works): 400 the request, 401 "invalid or missing
 *   API key", 402 "account requires activation or additional credits", 403 "insufficient permissions", 404,
 *   406, 429 "rate limit exceeded". The help centre adds that a call from an IP not authorised is blocked too
 *   (https://help.brevo.com/hc/en-us/articles/5740111683858), which is a 401 by its Make guide.
 * - An error is JSON with `code` and `message`, its example `invalid_parameter`, "Invalid email address" (the same
 *   page); the 400's codes are in https://developers.brevo.com/reference/send-transac-email, `duplicate_request`
 *   "identical request submitted too frequently" among them. No code is reserved for a bad recipient or an
 *   unverified sender, and a sender's may be the same `invalid_parameter`.
 * - On a 429, `x-sib-ratelimit-reset` is the time until the limit resets, "typically seconds"
 *   (https://developers.brevo.com/docs/limit-headers).
 * - A contact's call may answer 425, undescribed (https://developers.brevo.com/reference/createcontact).
 *
 * So one address alone is what fails at once: `invalid_parameter` whose message speaks of an email, and not of a
 * sender or a list. Any other refusal in Brevo's JSON waits for a person, since one that holds up everybody must
 * mend itself. A 4xx without Brevo's JSON is a proxy's, and may pass. Brevo's `message` is read for that and for
 * nothing else, never kept or logged: it may quote an address.
 */
final class BrevoAnswers
{
    public const int CONNECT_SECONDS = 3;

    /**
     * A security email waits inside a person's request, and a worker on every other. A sync makes four calls at
     * most, so even answered slowly it stays inside a worker's 60 s and the queue's retry_after of 90 s.
     */
    public const int SECONDS = 10;

    /** A file goes up first (the daily report's PDF), and only ever from a worker. */
    public const int SECONDS_WITH_FILE = 30;

    /** A reset that says less, a worker must not spin on; more is not believed: other jobs' waits end too. */
    public const int LEAST_WAIT = 5;

    public const int MOST_WAIT = 3600;

    /** A 429 with no reset to read. */
    public const int USUAL_WAIT = 60;

    /** The longest provider id `ops.sent_messages` keeps: one longer must not fail a send already made. */
    public const int MOST_ID = 255;

    /**
     * The key as Brevo wants it: what a paste leaves round it is dropped; one blank, or holding what no key
     * can, is refused here, naming nothing of it: it would fail on every call, or never reach one.
     *
     * @throws ProviderFailed
     */
    public static function key(#[\SensitiveParameter] string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            throw new ProviderFailed('Brevo: no API key is set (BREVO_API_KEY).', FailureKind::WaitsForPerson);
        }

        if (preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
            throw new ProviderFailed('Brevo: the API key is malformed (BREVO_API_KEY): it holds a space or a character no key has.', FailureKind::WaitsForPerson);
        }

        return $key;
    }

    /** What the failure means for the queue: it names only Brevo's status and a code that is plain words. */
    public static function refused(string $what, Response $response): ProviderFailed
    {
        $status = $response->status();
        $code = self::code($response);

        return new ProviderFailed(
            "Brevo refused {$what}: HTTP {$status}".($code === null ? '' : " ({$code})").'.',
            self::kind($response),
            $status === 429 ? self::reset($response) : null,
        );
    }

    /** A 2xx that is Brevo's own: empty or JSON. A proxy's page in HTML says nothing was done. */
    public static function isBrevos(Response $response): bool
    {
        return $response->successful() && ($response->body() === '' || is_array($response->json()));
    }

    /**
     * What is to be taken away is not there: the 404 Brevo documents for a list or a contact, in its JSON, or a
     * 400 saying so by its code.
     */
    public static function alreadyGone(Response $response): bool
    {
        return ($response->status() === 404 && is_array($response->json()))
            || ($response->status() === 400 && in_array(self::code($response), ['document_not_found', 'Contact email not found'], true));
    }

    /**
     * Adding a contact, what is not found can only be its list: `updateEnabled` makes the contact. Inferred: the
     * reference allows `document_not_found` here (https://developers.brevo.com/reference/createcontact) and does not
     * say what a list that is gone answers.
     */
    public static function listGone(Response $response): bool
    {
        return in_array($response->status(), [400, 404], true) && self::code($response) === 'document_not_found';
    }

    private static function kind(Response $response): FailureKind
    {
        $status = $response->status();

        if ($status === 429) {
            return FailureKind::RateLimited;
        }

        if ($status < 400 || $status >= 500 || in_array($status, [408, 425], true) || ! self::hasBrevosBody($response) || self::code($response) === 'duplicate_request') {
            return FailureKind::MayPass;
        }

        $message = $response->json('message');
        $words = is_string($message) ? strtolower($message) : '';

        return self::code($response) === 'invalid_parameter' && str_contains($words, 'email') && ! str_contains($words, 'sender') && ! str_contains($words, 'list')
            ? FailureKind::OneAddress
            : FailureKind::WaitsForPerson;
    }

    /** Brevo's errors carry `code`, `message` or both, as strings. */
    private static function hasBrevosBody(Response $response): bool
    {
        return is_array($response->json()) && (is_string($response->json('code')) || is_string($response->json('message')));
    }

    private static function code(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) && preg_match('/^[A-Za-z0-9_ ()-]{1,64}$/', $code) === 1 ? $code : null;
    }

    /** Compared as a float before any cast: a value past an int's range would cast to anything. */
    private static function reset(Response $response): int
    {
        $reset = $response->header('x-sib-ratelimit-reset');
        $seconds = is_numeric($reset) ? (float) $reset : 0.0;

        if (is_nan($seconds) || $seconds <= 0.0) {
            return self::USUAL_WAIT;
        }

        return $seconds >= self::MOST_WAIT ? self::MOST_WAIT : max(self::LEAST_WAIT, (int) ceil($seconds));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Http\Client\Response;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * What both Brevo adapters read the same way (ops.md §7): the key as pasted, how long to wait, and what a
 * refusal means. As Brevo documents it, read 2026-11-03:
 * - The statuses (https://developers.brevo.com/docs/how-it-works): 400 the request, 401 "invalid or missing
 *   API key", 402 "account requires activation or additional credits", 403 "insufficient permissions", 404,
 *   406, 429 "rate limit exceeded". The help centre adds that a call from an IP not authorised is blocked too
 *   (https://help.brevo.com/hc/en-us/articles/5740111683858), which is a 401 by its Make guide.
 * - The 400's codes (https://developers.brevo.com/reference/send-transac-email), `duplicate_request`
 *   "identical request submitted too frequently" among them. Which code an unverified sender gets is not
 *   said: every other 400 is taken as a person's to mend.
 * - On a 429, `x-sib-ratelimit-reset` is the time until the limit resets, "typically seconds"
 *   (https://developers.brevo.com/docs/limit-headers).
 * - A contact's call may answer 425, undescribed (https://developers.brevo.com/reference/createcontact).
 *
 * Brevo's `message` is never read: it may quote an address.
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

    /** A reset that says longer is not believed: the job's other attempts must still come. */
    private const int MOST_WAIT = 3600;

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
            throw new ProviderFailed('Brevo: no API key is set (BREVO_API_KEY).', permanent: true);
        }

        if (preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
            throw new ProviderFailed('Brevo: the API key is malformed (BREVO_API_KEY): it holds a space or a character no key has.', permanent: true);
        }

        return $key;
    }

    /** What the failure means for the queue: it names only Brevo's status and a code that is plain words. */
    public static function refused(string $what, Response $response): ProviderFailed
    {
        $status = $response->status();
        $code = self::code($response);
        $mayPass = $status < 400 || $status >= 500 || in_array($status, [408, 425, 429], true) || $code === 'duplicate_request';

        return new ProviderFailed(
            "Brevo refused {$what}: HTTP {$status}".($code === null ? '' : " ({$code})").'.',
            permanent: ! $mayPass,
            retryAfter: $status === 429 ? self::reset($response) : null,
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

    private static function code(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) && preg_match('/^[A-Za-z0-9_ ()-]{1,64}$/', $code) === 1 ? $code : null;
    }

    private static function reset(Response $response): ?int
    {
        $reset = $response->header('x-sib-ratelimit-reset');

        return is_numeric($reset) && (float) $reset > 0 ? min((int) ceil((float) $reset), self::MOST_WAIT) : null;
    }
}

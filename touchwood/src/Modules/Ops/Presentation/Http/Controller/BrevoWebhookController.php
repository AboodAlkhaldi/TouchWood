<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Ops\Application\Newsletter\CustomerSubscriptions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\Subscriber;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Brevo's marketing webhook (ops.md §1.9): someone unsubscribed in a newsletter, so they leave every
 * list - a guest is removed, a customer's consent switches off (Access). Answered at once with no body:
 * 204 taken (or not ours to act on), 401 refused, 413 too large, 429 too many - or our side broke.
 *
 * As Brevo documents it, read 2026-10-29:
 * - The webhook is made with type `marketing` and the event `unsubscribed`
 *   (https://developers.brevo.com/reference/create-webhook.md), and its `headers` - "custom headers to
 *   be send with webhooks" - carry our secret: Brevo signs nothing, and these headers are what it
 *   offers to authenticate a call (https://developers.brevo.com/docs/secured-webhooks.md). The owner
 *   sets `X-TouchWood-Secret` to BREVO_WEBHOOK_SECRET when making the webhook.
 * - The call's body for an unsubscribe is one JSON object with `"event": "unsubscribe"` and the
 *   contact's `email` (https://developers.brevo.com/docs/marketing-webhooks). A batched webhook's body
 *   is not documented there: only the single object is read, and the webhook must not be batched.
 *
 * - A call is tried again only on no answer or a 429, at about 10 minutes, 1 hour, 2 hours and 8 hours; any
 *   other 4xx or any 5xx "stop all retry attempts and discard the webhook"
 *   (https://developers.brevo.com/docs/retry-mechanism, read 2026-11-03). So whatever breaks on our side is
 *   answered 429, never 500: an unsubscribe thrown away would leave a person's consent on. Taken again, a call
 *   changes nothing, and one older than the row's joining leaves it alone: they agreed since. Something it cannot
 *   read is a 204 and a line in the log, naming nothing of the body: asked again it would say the same.
 *
 * With no secret set, every call is refused.
 */
final readonly class BrevoWebhookController
{
    public const string HEADER = 'X-TouchWood-Secret';

    public const int CALLS_A_MINUTE_AN_ADDRESS = 600;

    public const int MOST_BYTES = 100_000;

    /** What a 429 for our own failure says: Brevo's next try comes by its own schedule, from 10 minutes. */
    private const int TRY_AGAIN_SECONDS = 600;

    public function __construct(
        private Config $config,
        private CustomerSubscriptions $subscriptions,
        private LoggerInterface $log,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            return $this->take($request);
        } catch (Throwable $failed) {
            report($failed);

            return response()->noContent(429)->header('Retry-After', (string) self::TRY_AGAIN_SECONDS);
        }
    }

    private function take(Request $request): Response
    {
        $key = 'ops-brevo-webhook:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::CALLS_A_MINUTE_AN_ADDRESS)) {
            return response()->noContent(429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        $secret = $this->config->get('services.brevo.webhook_secret');
        $given = $request->header(self::HEADER);

        // What a paste into .env leaves round the secret is not part of what Brevo was given.
        $secret = is_string($secret) ? trim($secret) : '';

        if ($secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            return response()->noContent(401);
        }

        if (strlen($request->getContent()) > self::MOST_BYTES) {
            return response()->noContent(413);
        }

        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || ($body['event'] ?? null) !== 'unsubscribe' || ! is_string($body['email'] ?? null)) {
            return $this->notRead();
        }

        try {
            $email = Subscriber::email($body['email']);
        } catch (InvalidOpsInput) {
            return $this->notRead();
        }

        // `ts_event`: seconds, in UTC (https://developers.brevo.com/docs/marketing-webhooks). None: taken as now.
        $at = is_int($body['ts_event'] ?? null) && $body['ts_event'] > 0 ? CarbonImmutable::createFromTimestampUTC($body['ts_event'])->toDateTimeImmutable() : null;
        $this->subscriptions->unsubscribedAtProvider($email, $at);

        return response()->noContent();
    }

    /** A webhook that sends more than unsubscribes, or batches them, loses them: it is worth a line. */
    private function notRead(): Response
    {
        $this->log->warning('Ops: Brevo\'s webhook sent what it does not read: one unsubscribe object is all it reads, so check the webhook is the marketing "unsubscribed" one and not batched.');

        return response()->noContent();
    }
}

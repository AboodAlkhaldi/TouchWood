<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Ops\Application\Newsletter\CustomerSubscriptions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\Subscriber;

/**
 * Brevo's marketing webhook (ops.md §1.9): someone unsubscribed in a newsletter, so they leave every
 * list - a guest is removed, a customer's consent switches off (Access). Answered at once with no body:
 * 204 taken (or not ours to act on), 401 refused, 413 too large, 429 too many.
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
 * With no secret set, every call is refused.
 */
final readonly class BrevoWebhookController
{
    public const string HEADER = 'X-TouchWood-Secret';

    public const int CALLS_A_MINUTE_AN_ADDRESS = 600;

    public const int MOST_BYTES = 100_000;

    public function __construct(
        private Config $config,
        private CustomerSubscriptions $subscriptions,
    ) {}

    public function __invoke(Request $request): Response
    {
        $key = 'ops-brevo-webhook:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::CALLS_A_MINUTE_AN_ADDRESS)) {
            return response()->noContent(429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        $secret = $this->config->get('services.brevo.webhook_secret');
        $given = $request->header(self::HEADER);

        if (! is_string($secret) || $secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            return response()->noContent(401);
        }

        if (strlen($request->getContent()) > self::MOST_BYTES) {
            return response()->noContent(413);
        }

        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || ($body['event'] ?? null) !== 'unsubscribe' || ! is_string($body['email'] ?? null)) {
            return response()->noContent();
        }

        try {
            $email = Subscriber::email($body['email']);
        } catch (InvalidOpsInput) {
            return response()->noContent();
        }

        $this->subscriptions->unsubscribedAtProvider($email);

        return response()->noContent();
    }
}

<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Ops\Infrastructure\External\WhatsAppSettings;
use Modules\Ops\Infrastructure\Queue\HandleWhatsAppMessageJob;
use Modules\Ops\Infrastructure\Queue\HandleWhatsAppStatusJob;
use Throwable;

/**
 * Meta's notices about the WhatsApp number (ops.md §9.8 point 4), built like Brevo's webhook (§1.9): machine to
 * machine, no session, no CSRF, no name, a reserved path, answered during maintenance, throttled, its body capped.
 * As Meta documents it, read 2026-11-22 (webhooks/create-webhook-endpoint.md; webhooks/reference/messages/status.md):
 * - **GET** is the handshake: `hub.mode=subscribe`, a random `hub.challenge` and our `hub.verify_token`; answer 200
 *   with the challenge when the token matches, anything else otherwise. The token is compared in constant time.
 * - **POST** carries `X-Hub-Signature-256`: `sha256=` and the HMAC-SHA256 of the payload with the app secret, hex.
 *   Checked on the raw bytes, with `hash_equals`; no timestamp is part of it, so a notice can be replayed - every
 *   step after it is idempotent. Wrong or missing: 403 and nothing done.
 * - The body is `entry[].changes[].value`, with `metadata.phone_number_id`, `statuses[]` (`id`, `status`: sent,
 *   delivered, read, failed) and `messages[]` (`id`, `from`). Meta sends again with decreasing frequency for 7 days
 *   on anything but 200, and batches up to 1,000 updates.
 * - A `metadata.phone_number_id` that is not ours: 403, nothing done.
 *
 * Answered at once, with no body: 200 taken, 403 refused, 413 too large, 429 too many - or 500 when our side broke,
 * so Meta sends it again. The work is left to the queue: a **failed** status (HandleWhatsAppStatusJob) and a person's
 * message (HandleWhatsAppMessageJob, the auto-reply). Sent, delivered and read statuses change nothing and are
 * not queued. With no app secret, verify token or phone number id set, every call is refused (W3: the site goes on).
 */
final readonly class WhatsAppWebhookController
{
    public const int CALLS_A_MINUTE_AN_ADDRESS = 600;

    /** Meta batches up to 1,000 updates; a status is well under 1 KB. */
    public const int MOST_BYTES = 1_000_000;

    public function __construct(
        private WhatsAppSettings $settings,
        private Dispatcher $jobs,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            // One limiter for both: the handshake is open to anyone who can reach the address (M2 of the review).
            $key = 'ops-whatsapp-webhook:'.$request->ip();

            if (RateLimiter::tooManyAttempts($key, self::CALLS_A_MINUTE_AN_ADDRESS)) {
                return response()->noContent(429)->header('Retry-After', (string) RateLimiter::availableIn($key));
            }

            RateLimiter::hit($key, 60);

            return $request->isMethod('GET') ? $this->handshake($request) : $this->take($request);
        } catch (Throwable $failed) {
            report($failed);

            return response()->noContent(500);
        }
    }

    private function handshake(Request $request): Response
    {
        $token = $this->settings->verifyToken();
        $given = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($token === '' || $mode !== 'subscribe' || ! is_string($given) || ! hash_equals($token, $given) || ! is_string($challenge)) {
            return response()->noContent(403);
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    private function take(Request $request): Response
    {
        $length = $request->header('Content-Length');

        if ((is_string($length) && ctype_digit($length) && (int) $length > self::MOST_BYTES) || strlen($request->getContent()) > self::MOST_BYTES) {
            return response()->noContent(413);
        }

        $raw = $request->getContent();
        $secret = $this->settings->appSecret();
        $given = $request->header('X-Hub-Signature-256');

        if ($secret === '' || ! is_string($given) || ! str_starts_with($given, 'sha256=') || ! hash_equals(hash_hmac('sha256', $raw, $secret), substr($given, 7))) {
            return response()->noContent(403);
        }

        $body = json_decode($raw, true);

        if (! is_array($body)) {
            return response()->noContent(200);
        }

        $ours = $this->settings->phoneNumberId();
        $changes = [];

        foreach (is_array($body['entry'] ?? null) ? $body['entry'] : [] as $entry) {
            foreach (is_array($entry) && is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                $value = is_array($change) && is_array($change['value'] ?? null) ? $change['value'] : null;

                if ($value === null) {
                    continue;
                }

                // Every notice must name our number: one that does not is refused whole, and nothing is done.
                if ($ours === '' || ($value['metadata']['phone_number_id'] ?? null) !== $ours) {
                    return response()->noContent(403);
                }

                $changes[] = $value;
            }
        }

        foreach ($changes as $value) {
            $this->work($value);
        }

        return response()->noContent(200);
    }

    /** @param  array<string, mixed>  $value */
    private function work(array $value): void
    {
        foreach (is_array($value['statuses'] ?? null) ? $value['statuses'] : [] as $status) {
            if (is_array($status) && ($status['status'] ?? null) === 'failed' && is_string($status['id'] ?? null) && $status['id'] !== '') {
                $this->jobs->dispatch(new HandleWhatsAppStatusJob($status['id']));
            }
        }

        $own = array_filter([
            $this->settings->mainNumber(),
            self::digits($value['metadata']['display_phone_number'] ?? null),
        ], static fn (string $number): bool => $number !== '');

        foreach (is_array($value['messages'] ?? null) ? $value['messages'] : [] as $message) {
            $from = is_array($message) ? self::digits($message['from'] ?? null) : '';

            // Never a reply to our own numbers; a statuses-only notice has no messages at all.
            if (! is_array($message) || ! is_string($message['id'] ?? null) || $message['id'] === '' || $from === '' || in_array($from, $own, true)) {
                continue;
            }

            $this->jobs->dispatch(new HandleWhatsAppMessageJob($message['id'], $from));
        }
    }

    private static function digits(mixed $number): string
    {
        return is_string($number) || is_int($number) ? (preg_replace('/\D/', '', (string) $number) ?? '') : '';
    }
}

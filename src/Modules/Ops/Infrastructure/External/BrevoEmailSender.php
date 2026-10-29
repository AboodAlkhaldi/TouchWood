<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * Ops' emails through Brevo's transactional API (ops.md §2.2).
 *
 * The request is the one Brevo's reference shows for "Send a transactional email", read 2026-10-29:
 * https://developers.brevo.com/reference/sendtransacemail
 *   POST https://api.brevo.com/v3/smtp/email, the key in the `api-key` header, a JSON body of
 *   `sender` {email, name}, `to` [{email, name}], `subject`, `htmlContent`, `textContent`; a 201 answer
 *   carries `messageId`.
 *
 * `OutgoingEmail::$headers` are **not** passed on: the same reference's `headers` field, read
 * 2026-10-30, takes "custom email headers (non-standard headers)" and says "standard email headers
 * are not supported" - and `List-Unsubscribe` is a standard one (RFC 2369). Brevo's help centre says
 * Brevo adds its own List-Unsubscribe header to transactional emails
 * (https://help.brevo.com/hc/en-us/articles/19100260472850); that is not in the developer reference.
 *
 * The key is the server's (`BREVO_API_KEY`, services.brevo.key), never a setting or a table. What is
 * sent is never logged here, and a refusal's message names only Brevo's status and error code.
 */
final readonly class BrevoEmailSender implements EmailSender
{
    public const string ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    /** Brevo's limit for a sender's or recipient's name (the same reference). */
    private const int NAME_MAX = 70;

    public function __construct(
        private Factory $http,
        private string $apiKey,
    ) {}

    public function send(OutgoingEmail $email): ?string
    {
        if (trim($this->apiKey) === '') {
            throw new ProviderFailed('Brevo: no API key is set (BREVO_API_KEY).');
        }

        $to = ['email' => $email->toEmail];

        if ($email->toName !== null && trim($email->toName) !== '') {
            $to['name'] = mb_substr($email->toName, 0, self::NAME_MAX);
        }

        try {
            $response = $this->http
                ->withHeaders(['api-key' => $this->apiKey])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post(self::ENDPOINT, [
                    'sender' => ['email' => $email->fromEmail, 'name' => mb_substr($email->fromName, 0, self::NAME_MAX)],
                    'to' => [$to],
                    'subject' => $email->subject,
                    'htmlContent' => $email->html,
                    'textContent' => $email->text,
                ]);
        } catch (ConnectionException) {
            throw new ProviderFailed('Brevo: the API could not be reached.');
        }

        if ($response->status() !== 201) {
            $code = $response->json('code');

            throw new ProviderFailed('Brevo refused the email: HTTP '.$response->status().(is_string($code) ? " ({$code})" : '').'.');
        }

        $messageId = $response->json('messageId');

        return is_string($messageId) && $messageId !== '' ? $messageId : null;
    }
}

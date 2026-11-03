<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Modules\Ops\Application\Messages\EmailAttachment;
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
 *   carries `messageId`. A file goes in `attachment` [{name, content}], the content base64 - "the `name`
 *   field is required when `content` is provided"; pdf among the extensions allowed (read 2026-10-30).
 *
 * `OutgoingEmail::$headers` are **not** passed on: the same reference's `headers` field, read
 * 2026-10-30, takes "custom email headers (non-standard headers)" and says "standard email headers
 * are not supported" - and `List-Unsubscribe` is a standard one (RFC 2369). Brevo's help centre says
 * Brevo adds its own List-Unsubscribe header to transactional emails
 * (https://help.brevo.com/hc/en-us/articles/19100260472850); that is not in the developer reference.
 *
 * The key is the server's (`BREVO_API_KEY`, services.brevo.key), never a setting or a table. What is
 * sent is never logged here, and a refusal's message names only Brevo's status and error code. What each
 * failure means, and how long a call may take: BrevoAnswers.
 *
 * Taken as sent: the documented 201, and 202 "scheduled", whatever their body - `messageId` is optional in the
 * reference, and sending again would double the email; any other 2xx only with its `messageId` (a proxy's page
 * has none, and goes back to the queue).
 */
final readonly class BrevoEmailSender implements EmailSender
{
    public const string ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    /** Brevo's limit for a sender's or recipient's name (the same reference). */
    private const int NAME_MAX = 70;

    public function __construct(
        private Factory $http,
        #[\SensitiveParameter] private string $apiKey,
    ) {}

    public function send(OutgoingEmail $email): ?string
    {
        $key = BrevoAnswers::key($this->apiKey);

        if (trim($email->fromEmail) === '') {
            throw new ProviderFailed('Brevo: no sender address is set (MAIL_FROM_ADDRESS).', permanent: true);
        }

        $to = ['email' => $email->toEmail];
        $sender = ['email' => $email->fromEmail];

        if (trim($email->fromName) !== '') {
            $sender['name'] = mb_substr($email->fromName, 0, self::NAME_MAX);
        }

        if ($email->toName !== null && trim($email->toName) !== '') {
            $to['name'] = mb_substr($email->toName, 0, self::NAME_MAX);
        }

        $body = [
            'sender' => $sender,
            'to' => [$to],
            'subject' => $email->subject,
            'htmlContent' => $email->html,
            'textContent' => $email->text,
        ];
        if ($email->attachments !== []) {
            $body['attachment'] = array_map(static fn (EmailAttachment $file): array => ['name' => $file->name, 'content' => base64_encode($file->content)], $email->attachments);
        }

        try {
            $response = $this->http
                ->withHeaders(['api-key' => $key])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(BrevoAnswers::CONNECT_SECONDS)
                ->timeout($email->attachments === [] ? BrevoAnswers::SECONDS : BrevoAnswers::SECONDS_WITH_FILE)
                ->post(self::ENDPOINT, $body);
        } catch (ConnectionException) {
            throw new ProviderFailed('Brevo: the API could not be reached.');
        }

        $messageId = $response->json('messageId');
        $id = is_string($messageId) && $messageId !== '' && mb_strlen($messageId) <= BrevoAnswers::MOST_ID ? $messageId : null;

        if (in_array($response->status(), [201, 202], true) || ($response->successful() && $id !== null)) {
            return $id;
        }

        throw BrevoAnswers::refused('the email', $response);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * The one connection to Meta's WhatsApp Cloud API (ops.md §9.8), for the codes, the two alerts and the auto-reply.
 *
 * As Meta documents it, read 2026-11-22 (docs listed in the module README):
 * - The endpoint is `POST /<PHONE_NUMBER_ID>/messages` on `https://graph.facebook.com/<version>/`, the token as a
 *   bearer header, a JSON body (documentation/business-messaging/whatsapp/messages/send-messages.md).
 * - A message taken is answered with `messages[0].id`; the answer "confirms the request was accepted, not that the
 *   message was delivered" - delivery comes by webhook (the same page).
 * - A text message: `messaging_product`, `recipient_type`, `to`, `type: text`, `text.body` (the same page).
 * - A template message: `type: template`, `template.name`, `template.language.code`, `template.components`, a body
 *   component's `parameters` of `type: text` (templates/overview.md).
 *
 * **Not found in the pages reached** (so UNVERIFIED, written from Meta's authentication-template guide as remembered):
 * the authentication template's send payload - the code as the body's one parameter and again as the button's
 * (`type: button`, `sub_type: url`, `index: "0"`) - and the numeric error codes. Both are checked by the first real
 * send on the owner's account; WhatsAppCodeSender and WhatsAppAnswers name each place.
 *
 * What is sent is never logged here, and a refusal's message names only Meta's status and error code.
 */
final readonly class WhatsAppClient
{
    public const int CONNECT_SECONDS = 3;

    /** A security code waits inside a person's request. */
    public const int SECONDS = 10;

    public function __construct(
        private Factory $http,
        private WhatsAppSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the message, without `messaging_product`
     * @return string Meta's id for the message
     *
     * @throws ProviderFailed
     */
    public function send(#[\SensitiveParameter] array $payload): string
    {
        $url = "{$this->settings->graphUrl}/{$this->settings->graphVersion}/{$this->settings->phoneNumberId()}/messages";

        try {
            $response = $this->http
                ->withToken($this->settings->accessToken())
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_SECONDS)
                ->timeout(self::SECONDS)
                ->post($url, ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', ...$payload]);
        } catch (ConnectionException) {
            throw new ProviderFailed('WhatsApp: Meta could not be reached.');
        }

        $id = $response->json('messages.0.id');

        if ($response->successful() && is_string($id) && $id !== '' && mb_strlen($id) <= WhatsAppAnswers::MOST_ID) {
            return $id;
        }

        if ($response->successful()) {
            // A 2xx with no id is not Meta's answer (a proxy's page): sending again could double a message that went.
            throw new ProviderFailed('WhatsApp: Meta answered HTTP '.$response->status().' with no message id.', FailureKind::MayPass);
        }

        throw WhatsAppAnswers::refused($response);
    }

    /** A free-form text, which Meta allows only inside the 24 hours after the person wrote (the auto-reply). */
    public function sendText(#[\SensitiveParameter] string $to, #[\SensitiveParameter] string $body): string
    {
        return $this->send(['to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $body]]);
    }

    /**
     * An approved template, with the components its parameters need.
     *
     * @param  list<array<string, mixed>>  $components
     */
    public function sendTemplate(#[\SensitiveParameter] string $to, string $name, string $language, #[\SensitiveParameter] array $components = []): string
    {
        return $this->send([
            'to' => $to,
            'type' => 'template',
            'template' => ['name' => $name, 'language' => ['code' => $language], ...($components === [] ? [] : ['components' => $components])],
        ]);
    }
}

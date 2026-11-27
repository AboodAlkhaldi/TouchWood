<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Meta's settings, read from `services.whatsapp` (ops.md §9.8 point 2): the server's `.env` only, never a table or a
 * screen. Each is trimmed - what a paste leaves round a value is no part of it. **A missing one never stops the
 * site** (W3): `missingForCodes()` names what is not set, and the sender refuses a code with words instead.
 */
final readonly class WhatsAppSettings
{
    /** Pinned in config and checked against Meta's changelog when built. */
    public string $graphVersion;

    public string $graphUrl;

    public function __construct(
        private Config $config,
    ) {
        $this->graphVersion = $this->value('graph_version');
        $this->graphUrl = rtrim($this->value('graph_url'), '/');
    }

    public function phoneNumberId(): string
    {
        return $this->value('phone_number_id');
    }

    public function accessToken(): string
    {
        return $this->value('access_token');
    }

    public function codeTemplate(): string
    {
        return $this->value('code_template');
    }

    public function appSecret(): string
    {
        return $this->value('app_secret');
    }

    public function verifyToken(): string
    {
        return $this->value('verify_token');
    }

    /** The main number as digits only, for the auto-reply's wa.me link; empty when not set. */
    public function mainNumber(): string
    {
        return preg_replace('/\D/', '', $this->value('main_number')) ?? '';
    }

    public function alertTemplate(string $which): string
    {
        return $this->value($which === 'expiring' ? 'alert_key_expiring' : 'alert_key_refused');
    }

    /**
     * The approved name of an order update's template (ops.md §9.10): `placed`, `shipped` or `delivered`; empty when not set.
     */
    public function orderTemplate(string $which): string
    {
        return $this->value('order_'.$which);
    }

    /**
     * The settings an order update cannot go without: the number, the token and its template's name.
     *
     * @return list<string>
     */
    public function missingForOrder(string $which): array
    {
        return $this->missing([
            'WHATSAPP_PHONE_NUMBER_ID' => $this->phoneNumberId(),
            'WHATSAPP_ACCESS_TOKEN' => $this->accessToken(),
            'WHATSAPP_ORDER_'.strtoupper($which).'_TEMPLATE' => $this->orderTemplate($which),
        ]);
    }

    /**
     * The settings a code cannot go without, by their `.env` names. Never a value.
     *
     * @return list<string>
     */
    public function missingForCodes(): array
    {
        return $this->missing(['WHATSAPP_PHONE_NUMBER_ID' => $this->phoneNumberId(), 'WHATSAPP_ACCESS_TOKEN' => $this->accessToken(), 'WHATSAPP_CODE_TEMPLATE' => $this->codeTemplate()]);
    }

    /**
     * The settings a template alert cannot go without.
     *
     * @return list<string>
     */
    public function missingForAlert(string $which): array
    {
        return $this->missing([
            'WHATSAPP_PHONE_NUMBER_ID' => $this->phoneNumberId(),
            'WHATSAPP_ACCESS_TOKEN' => $this->accessToken(),
            $which === 'expiring' ? 'WHATSAPP_ALERT_KEY_EXPIRING' : 'WHATSAPP_ALERT_KEY_REFUSED' => $this->alertTemplate($which),
        ]);
    }

    /**
     * The settings the auto-reply cannot go without.
     *
     * @return list<string>
     */
    public function missingForReply(): array
    {
        return $this->missing(['WHATSAPP_PHONE_NUMBER_ID' => $this->phoneNumberId(), 'WHATSAPP_ACCESS_TOKEN' => $this->accessToken(), 'WHATSAPP_MAIN_NUMBER' => $this->mainNumber()]);
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function missing(array $values): array
    {
        return array_keys(array_filter($values, static fn (string $value): bool => $value === ''));
    }

    private function value(string $key): string
    {
        $value = $this->config->get("services.whatsapp.{$key}");

        return is_string($value) ? trim($value) : '';
    }
}

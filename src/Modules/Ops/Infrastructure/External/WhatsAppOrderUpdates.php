<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use LogicException;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\OrderUpdates;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Public\Enums\MessageKind;

/**
 * The order updates through Meta's WhatsApp Cloud API (ops.md §9.10): a utility template per kind, under its `.env`
 * name, in the customer's language (`ar` or `en`), its body's parameters as text in the template's order. A name not
 * set is the feature off for that kind: `isSetUp` says so before anything is queued.
 */
final readonly class WhatsAppOrderUpdates implements OrderUpdates
{
    public function __construct(
        private WhatsAppClient $client,
        private WhatsAppSettings $settings,
    ) {}

    public function isSetUp(MessageKind $kind): bool
    {
        return in_array($kind, self::KINDS, true) && $this->settings->missingForOrder(self::which($kind)) === [];
    }

    public function send(#[\SensitiveParameter] string $phone, string $locale, MessageKind $kind, #[\SensitiveParameter] array $parameters): string
    {
        $which = self::which($kind);
        $missing = $this->settings->missingForOrder($which);

        if ($missing !== []) {
            throw new ProviderFailed('WhatsApp: '.implode(', ', $missing).' is not set, so the order update cannot go.', FailureKind::WaitsForPerson);
        }

        return $this->client->sendTemplate($phone, $this->settings->orderTemplate($which), $locale === 'ar' ? 'ar' : 'en', [
            ['type' => 'body', 'parameters' => array_map(static fn (string $text): array => ['type' => 'text', 'text' => $text], $parameters)],
        ]);
    }

    private static function which(MessageKind $kind): string
    {
        return match ($kind) {
            MessageKind::OrderTaken => 'placed',
            MessageKind::OrderShipped => 'shipped',
            MessageKind::OrderDelivered => 'delivered',
            default => throw new LogicException("{$kind->value} has no WhatsApp order update."),
        };
    }
}

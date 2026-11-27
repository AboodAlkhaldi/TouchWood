<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use Modules\Ops\Public\Enums\MessageKind;

/**
 * The order updates a customer gets by WhatsApp beside the email (ops.md §9.10): C1 taken, C4 shipped, C5 delivered,
 * each an approved utility template whose words are fixed, with only the parameters sent.
 */
interface OrderUpdates
{
    /** The three kinds that go by WhatsApp too. */
    public const array KINDS = [MessageKind::OrderTaken, MessageKind::OrderShipped, MessageKind::OrderDelivered];

    /** Whether this kind's message can go at all: the number, the token and its template's name set. */
    public function isSetUp(MessageKind $kind): bool;

    /**
     * One update, its parameters in the template's order; Meta's id for it.
     *
     * @param  list<string>  $parameters
     *
     * @throws ProviderFailed
     */
    public function send(#[\SensitiveParameter] string $phone, string $locale, MessageKind $kind, #[\SensitiveParameter] array $parameters): string;
}

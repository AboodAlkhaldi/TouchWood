<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Modules\Ops\Public\Enums\MessageKind;

/**
 * One moment to tell someone about (ops.md §1.5, §1.6): the kind, the store, and what names the moment -
 * the ids and counts its event carried, never a name, an address or an amount. It is what a queued email
 * or SMS carries; the words are written again from the module's reads when it leaves (MessageWriter).
 */
final readonly class EventMessage
{
    /**
     * @param  array<string, string|int>  $subject  e.g. ['orderId' => '01…'], ['low' => 3, 'out' => 1]
     */
    public function __construct(
        public MessageKind $kind,
        public string $storeId,
        public array $subject,
    ) {}

    public function string(string $key): string
    {
        return (string) ($this->subject[$key] ?? '');
    }

    public function int(string $key): int
    {
        return (int) ($this->subject[$key] ?? 0);
    }
}

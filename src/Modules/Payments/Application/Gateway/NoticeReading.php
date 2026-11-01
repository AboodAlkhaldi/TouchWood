<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

/**
 * `$reference` is null for a notice of a kind we do not read (a refund, a balance transfer): it is thanked
 * and dropped.
 */
final readonly class NoticeReading
{
    /**
     * @param  array<array-key, mixed>  $payload  the card and contact details removed
     */
    public function __construct(
        public bool $signed,
        public ?string $reference = null,
        public array $payload = [],
    ) {}
}

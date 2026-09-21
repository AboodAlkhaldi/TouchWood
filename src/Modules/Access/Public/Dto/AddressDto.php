<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

/**
 * One address of a customer, as other modules see it (Access spec §2.4). Sales reads the customer's
 * addresses in the store being ordered from, default first, and puts a copy of the chosen one on
 * the order; Shipping prints `formatted`, which is that store's own layout.
 *
 * `isComplete` is false when the store's address format has asked for something since this address
 * was saved (amendment 41): it may not be used for an order until the customer completes it.
 */
final readonly class AddressDto
{
    /**
     * @param  array<string, string>  $fields  the store format's values, in its own order
     */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $storeId,
        public string $label,
        public string $recipientName,
        public string $phone,
        public array $fields,
        public ?float $latitude,
        public ?float $longitude,
        public bool $isDefault,
        public bool $isComplete,
        public string $formatted,
    ) {}
}

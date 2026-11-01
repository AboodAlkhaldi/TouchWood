<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query;

/**
 * An order's shipping as its pages show it (sales.md §9.5 #2, #3, #11): the carrier ordered with and the
 * carrier shipped with, each in both languages as snapshotted; the tracking link stored when it shipped
 * (none: no Track); whether the fee is still to be set by staff.
 */
final readonly class OrderShippingView
{
    public function __construct(
        public ?string $orderedCarrierId,
        public ?string $orderedCarrierAr,
        public ?string $orderedCarrierEn,
        public ?string $carrierId,
        public ?string $carrierAr,
        public ?string $carrierEn,
        public ?string $trackingLink,
        public bool $feePending,
    ) {}

    /** @param  array<string, mixed>  $order  a `sales.orders` row */
    public static function of(array $order): self
    {
        $text = static fn (string $column): ?string => ($order[$column] ?? null) === null ? null : (string) $order[$column];

        return new self(
            $text('ordered_carrier_id'), $text('ordered_carrier_ar'), $text('ordered_carrier_en'),
            $text('carrier_id'), $text('carrier_ar'), $text('carrier_en'), $text('tracking_link'), (bool) ($order['shipping_fee_pending'] ?? false),
        );
    }

    /** The carrier the customer sees (gap 6.7): the one it shipped with, else the one ordered — in their language. */
    public function carrier(string $locale): ?string
    {
        return $this->carrierEn !== null
            ? ($locale === 'en' ? $this->carrierEn : $this->carrierAr)
            : ($locale === 'en' ? $this->orderedCarrierEn : $this->orderedCarrierAr);
    }
}

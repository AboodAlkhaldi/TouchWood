<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Model\Shipment;
use Modules\Shipping\Public\Contracts\ShippingApi;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\ValueObject\StoreId;

/**
 * What staff mark an order shipped with, or correct it to (sales.md §1.5, §9.5 #3; gaps 6.3–6.5): **one
 * of the store's carriers** (`ShippingApi::carriers`, any of its countries, on or off), its names as they
 * are now — or **"Other carrier"**, a name typed by staff (1–100 characters) — the tracking number when
 * there is one (at most 100), and the link: **a typed link wins** (HTTPS, at most 500 characters); else
 * the carrier's pattern with the number; else none, and the customer sees no Track.
 */
final readonly class ShipmentInputs
{
    /** The most a carrier's name or a tracking number may hold (sales.md §5). */
    public const int MOST = 100;

    /** The most a tracking link may hold (shipping.md §1.3, as the carriers' patterns). */
    public const int MOST_LINK = 500;

    public function __construct(
        private ShippingApi $shipping,
    ) {}

    /** @throws InvalidSalesAttribute */
    public function of(string $storeId, ?string $carrierId, ?string $otherCarrier, ?string $trackingNumber, ?string $trackingLink): Shipment
    {
        $id = self::blank($carrierId) ? null : strtolower(trim((string) $carrierId));
        $other = self::blank($otherCarrier) ? null : trim((string) $otherCarrier);
        $number = self::blank($trackingNumber) ? null : LatinDigits::of(trim((string) $trackingNumber));
        $typed = self::blank($trackingLink) ? null : LatinDigits::of(trim((string) $trackingLink));

        if ($number !== null && (mb_strlen($number) > self::MOST || preg_match('/[\x00-\x1F\x7F]/', $number) === 1)) {
            throw new InvalidSalesAttribute('tracking_number', 'at most '.self::MOST.' characters, on one line');
        }

        if ($typed !== null && ! self::link($typed)) {
            throw new InvalidSalesAttribute('tracking_link', 'a full https:// address of at most '.self::MOST_LINK.' characters');
        }

        if ($id === null) {
            if ($other === null || mb_strlen($other) > self::MOST || preg_match('/[\x00-\x1F\x7F]/', $other) === 1) {
                throw new InvalidSalesAttribute('carrier', 'one of the store\'s carriers, or another carrier\'s name of 1 to '.self::MOST.' characters');
            }

            return new Shipment(null, $other, $other, $number, $typed);
        }

        if ($other !== null) {
            throw new InvalidSalesAttribute('carrier', 'one of the store\'s carriers or another carrier\'s name, not both');
        }

        $carrier = null;

        foreach ($this->shipping->carriers(StoreId::fromString($storeId)) as $each) {
            if ($each->id === $id) {
                $carrier = $each;
            }
        }

        if ($carrier === null) {
            throw new InvalidSalesAttribute('carrier', 'one of the store\'s carriers');
        }

        $link = $typed ?? ($number === null ? null : $this->shipping->trackingLink($carrier->id, $number));

        // The pattern with a long number could pass what the order keeps.
        if ($link !== null && mb_strlen($link) > self::MOST_LINK) {
            throw new InvalidSalesAttribute('tracking_number', 'shorter: the carrier\'s link would pass '.self::MOST_LINK.' characters');
        }

        return new Shipment($carrier->id, $carrier->nameAr, $carrier->nameEn, $number, $link);
    }

    private static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private static function link(string $link): bool
    {
        return mb_strlen($link) <= self::MOST_LINK
            && str_starts_with(strtolower($link), 'https://')
            && preg_match('/[\s\x00-\x1F\x7F]/u', $link) !== 1
            && filter_var($link, FILTER_VALIDATE_URL) !== false;
    }
}

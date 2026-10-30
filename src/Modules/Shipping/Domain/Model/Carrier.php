<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Model;

use Modules\Shipping\Domain\ValueObject\CarrierDetails;

/**
 * A carrier (shipping.md §1.3): one store's, shipping in one of that store's countries — fixed once
 * it exists, since its prices name places of that country. On or off (§4: `ON` ⇄ `OFF`); deleted
 * only while off. Its place in the list is kept within its country (owner, 2026-10-29, step 3 Q5).
 */
final class Carrier
{
    private function __construct(
        public readonly string $id,
        public readonly string $storeId,
        public readonly string $countryCode,
        private CarrierDetails $details,
        private ?string $logoMediaId,
        private ?int $freeAboveMinor,
        private bool $active,
        public readonly int $position,
    ) {}

    public static function create(string $id, string $storeId, string $countryCode, CarrierDetails $details, ?string $logoMediaId, bool $active, int $position): self
    {
        return new self($id, $storeId, $countryCode, $details, $logoMediaId, null, $active, $position);
    }

    public static function reconstitute(string $id, string $storeId, string $countryCode, CarrierDetails $details, ?string $logoMediaId, ?int $freeAboveMinor, bool $active, int $position): self
    {
        return new self($id, $storeId, $countryCode, $details, $logoMediaId, $freeAboveMinor, $active, $position);
    }

    public function change(CarrierDetails $details, ?string $logoMediaId): void
    {
        $this->details = $details;
        $this->logoMediaId = $logoMediaId;
    }

    /** Its own free-shipping amount (§1.5): from this goods total its fee is 0; null for never. */
    public function setFreeAbove(?int $amountMinor): void
    {
        $this->freeAboveMinor = $amountMinor;
    }

    public function switch(bool $on): void
    {
        $this->active = $on;
    }

    public function dropLogo(): void
    {
        $this->logoMediaId = null;
    }

    public function details(): CarrierDetails
    {
        return $this->details;
    }

    public function logoMediaId(): ?string
    {
        return $this->logoMediaId;
    }

    /** Its own free-shipping amount (§1.5); null for none. */
    public function freeAboveMinor(): ?int
    {
        return $this->freeAboveMinor;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * What the audit keeps of it, both languages (§3: "every change is audited, in both languages").
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'country' => $this->countryCode,
            'name_ar' => $this->details->nameAr,
            'name_en' => $this->details->nameEn,
            'logo_media_id' => $this->logoMediaId,
            'phone' => $this->details->phone,
            'website' => $this->details->website,
            'tracking_pattern' => $this->details->trackingPattern,
            'free_above' => $this->freeAboveMinor === null ? null : (string) $this->freeAboveMinor,
            'active' => $this->active ? 'on' : 'off',
        ];
    }
}

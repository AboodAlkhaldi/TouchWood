<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Carrier;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Shipping\Domain\Exception\CarrierNameTaken;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Domain\ValueObject\CarrierDetails;

/**
 * The checks a carrier's form needs from outside its own values: its logo is a public image of the
 * media library (§1.3: "Platform media, public"), and no other carrier of the store has either of
 * its names, ignoring case and outer spaces (step 3 Q4). Asked inside the change's transaction,
 * after the store's lock, so a retried attempt asks again.
 */
final readonly class CarrierInputs
{
    public function __construct(
        private PlatformApi $platform,
        private CarrierRepository $carriers,
    ) {}

    /**
     * @return string|null the logo's media id, lower-case; null for none
     *
     * @throws InvalidShippingInput
     */
    public function logo(?string $mediaId): ?string
    {
        if ($mediaId === null || trim($mediaId) === '') {
            return null;
        }

        $media = $this->platform->media(strtolower(trim($mediaId)));

        if ($media === null || $media->visibility !== MediaVisibility::Public || ! str_starts_with($media->mime, 'image/')) {
            throw new InvalidShippingInput('logo_media_id', 'a public image of the media library');
        }

        return $media->id;
    }

    /**
     * @throws CarrierNameTaken
     */
    public function requireFreeNames(string $storeId, CarrierDetails $details, ?string $exceptId = null): void
    {
        foreach (['ar' => $details->nameAr, 'en' => $details->nameEn] as $language => $name) {
            $other = $this->carriers->named($storeId, $language, $name, $exceptId);

            if ($other !== null) {
                throw new CarrierNameTaken($language, $other->details()->nameAr, $other->details()->nameEn);
            }
        }
    }
}

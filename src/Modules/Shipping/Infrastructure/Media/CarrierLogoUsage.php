<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Media;

use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MediaUseDto;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * Carriers' logos are public media (shipping.md §1.3): deleting one from the media library leaves its
 * carriers with no logo. Detaching changes a carrier, so it needs the carriers job in each store
 * whose carrier shows it - asked of all of them before anything changes.
 */
final readonly class CarrierLogoUsage implements MediaUsage
{
    public function __construct(
        private CarrierRepository $carriers,
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    public function usesOf(string $mediaId): array
    {
        return array_map(static fn ($carrier): MediaUseDto => new MediaUseDto('shipping.carrier', $carrier->id, false), $this->carriers->withLogo($mediaId));
    }

    public function detach(string $mediaId): void
    {
        $stores = array_values(array_unique(array_map(static fn ($carrier): string => $carrier->storeId, $this->carriers->withLogo($mediaId))));
        sort($stores);

        foreach ($stores as $storeId) {
            $this->authorizer->authorize(ShippingPermissions::CARRIERS_MANAGE, PermissionScope::store(StoreId::fromString($storeId)));
        }

        // Each store's lock, in one order, then its carriers read again under it.
        foreach ($stores as $storeId) {
            $this->carriers->lockStore($storeId);
        }

        foreach ($this->carriers->withLogo($mediaId) as $carrier) {
            $was = $carrier->snapshot();
            $carrier->dropLogo();
            $this->carriers->update($carrier);
            $this->platform->recordAudit(ShippingAudit::entry('carrier', 'logo_detached', $carrier->id, $carrier->storeId, $was, $carrier->snapshot()));
        }
    }
}

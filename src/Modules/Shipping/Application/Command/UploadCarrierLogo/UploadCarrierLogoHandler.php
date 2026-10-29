<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\UploadCarrierLogo;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\ShippingPermissions;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A carrier's logo** under the store's carriers job. Platform keeps the file — one copy of an
 * identical public image — checks its type and size, and audits the upload under Shipping's job
 * (`uploadMediaFor`). Nothing changes in the carrier until its form is saved, and its handler checks
 * the image again (`CarrierInputs`).
 */
final readonly class UploadCarrierLogoHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_MANAGE;

    public function __construct(
        private CarrierChange $change,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the logo's media id
     *
     * @throws DomainError|Unauthorized
     */
    public function handle(UploadCarrierLogo $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        return $this->platform->uploadMediaFor(new ModuleUploadDto(
            'shipping',
            self::PERMISSION,
            PermissionScope::store(StoreId::fromString($store->id)),
            MediaVisibility::Public,
            $command->path,
            $command->fileName,
        ));
    }
}

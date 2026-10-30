<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Box;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;

/**
 * A change to one store's boxes or hand-packed sizes (shipping.md §1.7, §3): staff holding Manage Boxes
 * in that store; then the store's boxes' lock, the change and its audit in one transaction.
 */
final readonly class BoxChange
{
    public const string PERMISSION = ShippingPermissions::BOXES_MANAGE;

    public function __construct(
        private CarrierChange $carriers,
        private PlatformApi $platform,
        private BoxRepository $boxes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidShippingInput
     */
    public function authorize(string $storeId): StoreDto
    {
        return $this->carriers->authorize(self::PERMISSION, $storeId);
    }

    /**
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work
     * @return T
     */
    public function run(string $storeId, Closure $work): mixed
    {
        return $this->db->transaction(function () use ($storeId, $work): mixed {
            $this->boxes->lockStore($storeId);
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListBoxes;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Shipping\Application\Box\BoxChange;
use Modules\Shipping\Application\ShippingSettings;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Model\Box;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

final readonly class ListBoxesHandler
{
    public const string PERMISSION = BoxChange::PERMISSION;

    public function __construct(
        private BoxChange $change,
        private BoxRepository $boxes,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws InvalidShippingInput|Unauthorized
     */
    public function handle(ListBoxes $query): StoreBoxes
    {
        $store = $this->change->authorize($query->storeId);

        return new StoreBoxes(
            $this->platform->setting(ShippingSettings::PADDING_MM, StoreId::fromString($store->id))->int(),
            array_map(static function (Box $box): BoxRow {
                [$length, $width, $height] = $box->sizes();

                return new BoxRow($box->id, $box->name(), $length, $width, $height, $box->maxWeightG(), $box->isActive(), $box->position);
            }, $this->boxes->inStore($store->id)),
        );
    }
}

<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\ChangeBox;

use Modules\Shipping\Application\Box\BoxChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Domain\Exception\BoxNameTaken;
use Modules\Shipping\Domain\Exception\BoxNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;

final readonly class ChangeBoxHandler
{
    public const string PERMISSION = BoxChange::PERMISSION;

    public function __construct(
        private BoxChange $change,
        private BoxRepository $boxes,
    ) {}

    /**
     * @throws BoxNameTaken|BoxNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(ChangeBox $command): void
    {
        $store = $this->change->authorize($command->storeId);

        $this->change->run($store->id, function () use ($store, $command): array {
            $box = $this->boxes->byId($store->id, $command->boxId) ?? throw new BoxNotFound($command->boxId);
            $was = $box->snapshot();
            $box->change($command->name, $command->lengthMm, $command->widthMm, $command->heightMm, $command->maxWeightG);

            $other = $this->boxes->named($store->id, $box->name(), $box->id);

            if ($other !== null) {
                throw new BoxNameTaken($other->name());
            }

            // Nothing changed: nothing to write, nothing to audit.
            if ($was === $box->snapshot()) {
                return [null, []];
            }

            $this->boxes->update($box);

            return [null, [ShippingAudit::entry('box', 'changed', $box->id, $store->id, $was, $box->snapshot())]];
        });
    }
}

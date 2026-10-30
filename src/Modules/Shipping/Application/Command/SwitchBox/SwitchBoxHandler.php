<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SwitchBox;

use Modules\Shipping\Application\Box\BoxChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Domain\Exception\BoxNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;

final readonly class SwitchBoxHandler
{
    public const string PERMISSION = BoxChange::PERMISSION;

    public function __construct(
        private BoxChange $change,
        private BoxRepository $boxes,
    ) {}

    /**
     * @throws BoxNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(SwitchBox $command): void
    {
        $store = $this->change->authorize($command->storeId);

        $this->change->run($store->id, function () use ($store, $command): array {
            $box = $this->boxes->byId($store->id, $command->boxId) ?? throw new BoxNotFound($command->boxId);

            if ($box->isActive() === $command->on) {
                return [null, []];
            }

            $was = $box->snapshot();
            $box->switch($command->on);
            $this->boxes->update($box);

            return [null, [ShippingAudit::entry('box', $command->on ? 'switched_on' : 'switched_off', $box->id, $store->id, $was, $box->snapshot())]];
        });
    }
}

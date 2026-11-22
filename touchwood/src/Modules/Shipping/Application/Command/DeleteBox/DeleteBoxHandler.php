<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\DeleteBox;

use Modules\Shipping\Application\Box\BoxChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Domain\Exception\BoxNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;

final readonly class DeleteBoxHandler
{
    public const string PERMISSION = BoxChange::PERMISSION;

    public function __construct(
        private BoxChange $change,
        private BoxRepository $boxes,
    ) {}

    /**
     * @throws BoxNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(DeleteBox $command): void
    {
        $store = $this->change->authorize($command->storeId);

        $this->change->run($store->id, function () use ($store, $command): array {
            $box = $this->boxes->byId($store->id, $command->boxId) ?? throw new BoxNotFound($command->boxId);
            $this->boxes->delete($box->id);

            return [null, [ShippingAudit::entry('box', 'deleted', $box->id, $store->id, [...$box->snapshot(), 'position' => (string) $box->position], [])]];
        });
    }
}

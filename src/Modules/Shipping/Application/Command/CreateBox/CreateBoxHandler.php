<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\CreateBox;

use Modules\Shipping\Application\Box\BoxChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Domain\Exception\BoxNameTaken;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Model\Box;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;

final readonly class CreateBoxHandler
{
    public const string PERMISSION = BoxChange::PERMISSION;

    public function __construct(
        private BoxChange $change,
        private BoxRepository $boxes,
    ) {}

    /**
     * @return string the new box's id
     *
     * @throws BoxNameTaken|InvalidShippingInput|Unauthorized
     */
    public function handle(CreateBox $command): string
    {
        $store = $this->change->authorize($command->storeId);

        return $this->change->run($store->id, function () use ($store, $command): array {
            $box = Box::create(
                $this->boxes->nextId(), $store->id, $command->name,
                $command->lengthMm, $command->widthMm, $command->heightMm, $command->maxWeightG,
                $command->active, count($this->boxes->inStore($store->id)) + 1,
            );

            $other = $this->boxes->named($store->id, $box->name(), null);

            if ($other !== null) {
                throw new BoxNameTaken($other->name());
            }

            $this->boxes->add($box);

            return [$box->id, [ShippingAudit::entry('box', 'created', $box->id, $store->id, [], [...$box->snapshot(), 'position' => (string) $box->position])]];
        });
    }
}

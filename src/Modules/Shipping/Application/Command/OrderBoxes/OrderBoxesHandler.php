<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\OrderBoxes;

use Modules\Shipping\Application\Box\BoxChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Domain\Exception\BoxNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;

final readonly class OrderBoxesHandler
{
    public const string PERMISSION = BoxChange::PERMISSION;

    public function __construct(
        private BoxChange $change,
        private BoxRepository $boxes,
    ) {}

    /**
     * @throws BoxNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(OrderBoxes $command): void
    {
        $store = $this->change->authorize($command->storeId);
        $sent = array_map(static fn (string $id): string => strtolower(trim($id)), $command->boxIds);

        if (count(array_unique($sent)) !== count($sent)) {
            throw new InvalidShippingInput('boxes', 'each box once');
        }

        $this->change->run($store->id, function () use ($store, $sent): array {
            $was = [];

            foreach ($this->boxes->inStore($store->id) as $box) {
                $was[$box->id] = $box->position;
            }

            // Every id sent is one of this store's boxes: another store's is not found.
            foreach ($sent as $id) {
                if (! array_key_exists($id, $was)) {
                    throw new BoxNotFound($id);
                }
            }

            $order = [...$sent, ...array_values(array_diff(array_keys($was), $sent))];
            $now = [];
            $entries = [];

            foreach ($order as $index => $id) {
                $now[$id] = $index + 1;

                if ($was[$id] !== $index + 1) {
                    $entries[] = ShippingAudit::entry('box', 'ordered', $id, $store->id, ['position' => (string) $was[$id]], ['position' => (string) ($index + 1)]);
                }
            }

            if ($entries !== []) {
                $this->boxes->writePositions($now);
            }

            return [null, $entries];
        });
    }
}

<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SetSizePacking;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Shipping\Application\Box\BoxChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

final readonly class SetSizePackingHandler
{
    public const string PERMISSION = BoxChange::PERMISSION;

    public function __construct(
        private BoxChange $change,
        private BoxRepository $boxes,
        private CatalogApi $catalog,
    ) {}

    /**
     * A size is marked by hand only while it is Catalog's and not archived; any size may go back to the
     * suggestion, so one archived since is never stuck.
     *
     * @throws InvalidShippingInput|Unauthorized
     */
    public function handle(SetSizePacking $command): void
    {
        $store = $this->change->authorize($command->storeId);
        $variantId = strtolower(trim($command->variantId));

        if (! Ulids::valid($variantId)) {
            throw new InvalidShippingInput('variant', 'a product size');
        }

        if ($command->byHand) {
            $variant = $this->catalog->variant($variantId);

            if ($variant === null || $variant->isArchived) {
                throw new InvalidShippingInput('variant', 'a product size in use');
            }
        }

        $this->change->run($store->id, function () use ($store, $command, $variantId): array {
            if (! $this->boxes->setHandPacked($store->id, $variantId, $command->byHand)) {
                return [null, []];
            }

            [$was, $now] = $command->byHand ? ['suggestion', 'by hand'] : ['by hand', 'suggestion'];

            return [null, [ShippingAudit::entry('size_packing', 'changed', $variantId, $store->id, ['packing' => $was], ['packing' => $now])]];
        });
    }
}

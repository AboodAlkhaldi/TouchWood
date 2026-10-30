<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListPackingSizes;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantsDto;
use Modules\Shipping\Application\Box\BoxChange;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Catalog's own search over the store's sizes, kept to those packed by hand - or to the others - so the
 * list pages and searches as every list of sizes does. Archived sizes are left out of the adding search.
 */
final readonly class ListPackingSizesHandler
{
    public const string PERMISSION = BoxChange::PERMISSION;

    public function __construct(
        private BoxChange $change,
        private BoxRepository $boxes,
        private CatalogApi $catalog,
    ) {}

    /**
     * @throws InvalidShippingInput|Unauthorized
     */
    public function handle(ListPackingSizes $query): FoundVariantsDto
    {
        $store = $this->change->authorize($query->storeId);
        $packed = $this->boxes->allHandPacked($store->id);

        if ($query->byHand && $packed === []) {
            return new FoundVariantsDto([], null, 0);
        }

        return $this->catalog->searchVariants(
            StoreId::fromString($store->id),
            $query->text,
            only: $query->byHand ? $packed : null,
            except: $query->byHand ? null : $packed,
            after: $query->after,
            notArchived: ! $query->byHand,
        );
    }
}

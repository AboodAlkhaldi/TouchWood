<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\SearchGiftSizes;

use InvalidArgumentException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\GiftSizeRow;
use Modules\Promotions\Application\Query\MarketingReader;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The gift-level dialog's size search (promotions.md M4): every ready product's sizes matching the text,
 * archived ones left out, 20 at most, each with whether its normal sales already depend on its stock and
 * whether the store is wired — so the screen never reads Catalog or Inventory itself. For whoever may
 * create or change a gift level there.
 */
final readonly class SearchGiftSizesHandler
{
    public const string PERMISSION = PromotionsPermissions::GIFT_MANAGE;

    /** The most sizes a search shows. */
    public const int MOST = 20;

    public function __construct(
        private Authorizer $authorizer,
        private MarketingReader $reader,
        private CatalogApi $catalog,
        private InventoryApi $inventory,
        private StoreWiring $wiring,
        private PlatformApi $platform,
    ) {}

    /**
     * @return list<GiftSizeRow>
     *
     * @throws Unauthorized
     */
    public function handle(SearchGiftSizes $query): array
    {
        try {
            $store = StoreId::fromString($query->storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized(self::PERMISSION);
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $this->reader->open($store);

        // A store there is not has no sizes (a reader covering every store may name one).
        if ($this->platform->store($store) === null) {
            return [];
        }

        $text = trim($query->text);
        $found = $this->catalog->searchVariants($store, $text === '' ? null : mb_substr($text, 0, 100), anyReady: true, limit: self::MOST, notArchived: true, withTotal: false)->rows;

        if ($found === []) {
            return [];
        }

        $stock = $this->inventory->stock($store, array_map(static fn (FoundVariantDto $row): string => $row->variantId, $found));
        $wired = $this->wiring->isWired($store);

        return array_map(static fn (FoundVariantDto $row): GiftSizeRow => new GiftSizeRow(
            $row->variantId,
            $row->productNameAr,
            $row->productNameEn,
            $row->code,
            $row->photoMediaId,
            $stock[$row->variantId]->countsOnStock ?? false,
            $wired,
        ), $found);
    }
}

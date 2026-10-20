<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\SearchGiftSizes;

use InvalidArgumentException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\GiftSizeRow;
use Modules\Promotions\Application\Query\MarketingReader;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The gift-level dialog's size search (promotions.md M4): the store's ready sizes matching the text,
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

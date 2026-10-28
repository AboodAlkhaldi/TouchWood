<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Storefront;

use Illuminate\Contracts\Translation\Translator;
use Modules\Platform\Public\Contracts\ShopFramePart;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Application\Query\CountCartPieces\CountCartPieces;
use Modules\Sales\Application\Query\CountCartPieces\CountCartPiecesHandler;

/**
 * Sales's part of every shop page's frame (sales.md §4.1 S3): the header's cart, its words in the page's
 * language so a page that names none of Sales's words still draws it, and the count of pieces in this
 * shopper's cart here (§4.2 B13; R1-10) - asked on every page, as the frame's parts are (one query, none
 * without a cart).
 */
final readonly class SalesFramePart implements ShopFramePart
{
    public function __construct(
        private Translator $translator,
        private CountCartPiecesHandler $pieces,
    ) {}

    public function partFor(StoreDto $store, string $locale): array
    {
        return [
            'pieces' => $this->pieces->handle(new CountCartPieces($store->id)),
            'words' => [
                'cart' => (string) $this->translator->get('sales::shop_cart.header', [], $locale),
                // The count's plural forms, picked in the browser by the page's language.
                'pieces' => (array) $this->translator->get('sales::shop_cart.pieces', [], $locale),
            ],
        ];
    }
}

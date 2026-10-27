<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\BuyAgain;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccountType;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Sales\Application\Query\Own\OwnReader;
use Modules\Sales\Application\Query\Own\OwnReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Public\Enums\CartLineProblem;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Buy Again** (sales.md §1.1, §3.1; the screens' B6): the customer's own order, of any state, put back in
 * that store's cart — each of its lines but the gift, the parts of one size and mode as one, added to what
 * the cart holds as the cart adds (§1.1). A line the store does not sell now, or does not sell to this
 * account, is left out and named with why (`CartLineProblem`); every other goes in as it was, the cart
 * marking what checkout would refuse — a minimum or a most now passed, stock, a price.
 */
final readonly class BuyAgainHandler
{
    public const string PERMISSION = SalesPermissions::CART_MANAGE;

    public function __construct(
        private OwnReader $reader,
        private OwnReads $reads,
        private Authorizer $authorizer,
        private Carts $carts,
        private CatalogApi $catalog,
        private AccessApi $access,
        private B2BApi $b2b,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound
     */
    public function handle(BuyAgain $command): BuyAgainResult
    {
        $customerId = $this->reader->authorize();
        $number = OwnReader::number($command->orderNumber) ?? throw new OrderNotFound;
        $order = $this->reads->order($customerId, $number) ?? throw new OrderNotFound;
        $store = StoreId::fromString((string) $order['store_id']);
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));

        // The order's lines but the gift, each size and mode once (an edit's parts added together).
        $wanted = [];

        foreach ($this->reads->lines((string) $order['id']) as $line) {
            if ((bool) $line['is_gift']) {
                continue;
            }

            $key = $line['variant_id'].'|'.$line['sale_mode'];
            $wanted[$key] ??= ['variantId' => (string) $line['variant_id'], 'mode' => SaleMode::from((string) $line['sale_mode']), 'quantity' => 0, 'nameAr' => (string) $line['name_ar'], 'nameEn' => (string) $line['name_en']];
            $wanted[$key]['quantity'] += (int) $line['quantity'];
        }

        $company = $this->access->customer($customerId)?->accountType === AccountType::Company;
        $approved = $company && $this->b2b->isApproved($customerId, $store->value);
        $storeOn = $this->platform->store($store)->isActive ?? false;
        $variants = $wanted === [] ? [] : $this->catalog->storeVariants($store, array_values(array_unique(array_column($wanted, 'variantId'))));
        $productIds = array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, $variants)));
        $open = $productIds === [] ? [] : array_flip($this->catalog->productsOpenTo($store, $company ? ShopperKind::Company : ShopperKind::Individual, $productIds));
        $adding = [];
        $skipped = [];

        foreach ($wanted as $line) {
            $variant = $variants[$line['variantId']] ?? null;
            // As the cart marks a line (ViewCart): not sold here now, or not to this account.
            $reason = match (true) {
                ! $storeOn || $variant === null || ! $variant->isActive || ! $variant->orderable || $variant->notAvailableNow
                    || ! in_array($line['mode'], $variant->saleModes, true) => CartLineProblem::NotAvailable,
                ($line['mode'] === SaleMode::Wholesale && ! $approved) || ! isset($open[$variant->productId]) => CartLineProblem::NotForYourAccount,
                default => null,
            };

            if ($reason === null) {
                $adding[] = $line;
            } else {
                $skipped[] = new BuyAgainSkipped($line['variantId'], $line['nameAr'], $line['nameEn'], $line['mode']->value, $line['quantity'], $reason);
            }
        }

        if ($adding !== []) {
            $this->db->transaction(function () use ($store, $customerId, $adding): void {
                $now = CarbonImmutable::now();
                $cart = $this->carts->lockOrCreate($store, $customerId, null, $now);

                foreach ($adding as $line) {
                    $this->carts->add($cart->id, $line['variantId'], $line['mode'], $line['quantity'], $now);
                }
            }, 3);
        }

        return new BuyAgainResult($store->value, count($adding), $skipped);
    }
}

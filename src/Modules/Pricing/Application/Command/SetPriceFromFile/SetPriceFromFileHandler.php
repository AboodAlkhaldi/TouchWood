<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\SetPriceFromFile;

use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\RetailPriceWriter;
use Modules\Pricing\Application\Prices\SizeInput;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Domain\ValueObject\PriceSource;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The store file's price** (pricing.md §1.8): the retail price of each size the item switched on, set
 * as staff set one - the same writer, the ceiling held, audited from and to - with its source the
 * file's. Run by the system after Catalog's change commits (Catalog's handler checked
 * `catalog.listing.fill`), never inside it: Catalog holds its products' lock there, and Pricing takes
 * its store's lock before telling Catalog, so the two orders would meet. An off store's too (§1.1 rule
 * 8); a wired store's prices are the provider's and are left alone.
 */
final readonly class SetPriceFromFileHandler
{
    /** The system's, for the file's switching on; staff set prices under it themselves. */
    public const string PERMISSION = PricingPermissions::PRICE_EDIT;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private StoreWiring $wiring,
        private SizeInput $sizes,
        private RetailPriceWriter $prices,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|PriceTooPrecise|PriceNotPositive|OverCeiling
     */
    public function handle(SetPriceFromFile $command): void
    {
        try {
            $storeId = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidPricingInput('store');
        }

        // Answered for the system itself (Access's RoleAuthorizer). No off-store check here: whoever
        // switched the item on may fill that store, off or not (§1.1 rule 8: "or by its file") -
        // Catalog asked that; PricingChange::authorize would ask whether *they* may switch stores.
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($storeId));
        $store = $this->platform->store($storeId) ?? throw new InvalidPricingInput('store');

        if ($this->wiring->isWired($storeId) || $command->variantIds === []) {
            return;
        }

        $amount = AmountInput::of($command->price, $store);
        $amounts = array_fill_keys(array_values($this->sizes->check($command->variantIds)), $amount);

        $this->prices->set($store, $amounts, PriceSource::File, 'pricing.retail_price.filed');
    }
}

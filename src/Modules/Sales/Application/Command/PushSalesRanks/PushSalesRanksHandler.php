<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PushSalesRanks;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The shop's Best-Selling ranks, each night** (sales.md §1.13; catalog.md amendment 30): in each store, its
 * products ranked by **the pieces sold in that store's orders placed in the last 90 days that are approved
 * or later and were not cancelled, rejected or undelivered** (a waiting order is not yet sold, answer 51)
 * — a gift is not sold — 1 the most; a tie goes to the newer product,
 * as the shop's own tie-break. Every store, on and off, gets its whole ranking, so a product that sold
 * nothing loses its rank. The system only.
 */
final readonly class PushSalesRanksHandler
{
    public const string PERMISSION = SalesPermissions::SYSTEM_RUN;

    /** The window counted, in days (my assumption, stated for the owner to reject). */
    public const int DAYS = 90;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Orders $orders,
        private PlatformApi $platform,
        private ListingFacts $listing,
    ) {}

    /**
     * @return int how many stores were ranked
     *
     * @throws Unauthorized
     */
    public function handle(PushSalesRanks $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::System || $actor->requestedBy !== null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $since = CarbonImmutable::now()->subDays(self::DAYS);
        $stores = $this->platform->allStores();

        foreach ($stores as $store) {
            $this->listing->salesRanks(StoreId::fromString($store->id), self::ranks($this->orders->piecesSold($store->id, $since)));
        }

        return count($stores);
    }

    /**
     * @param  array<string, int>  $pieces  product id => pieces sold
     * @return array<string, int> product id => rank, 1 the most
     */
    private static function ranks(array $pieces): array
    {
        $ids = array_keys($pieces);
        usort($ids, static fn (string $a, string $b): int => [$pieces[$b], $b] <=> [$pieces[$a], $a]);

        return $ids === [] ? [] : array_combine($ids, range(1, count($ids)));
    }
}

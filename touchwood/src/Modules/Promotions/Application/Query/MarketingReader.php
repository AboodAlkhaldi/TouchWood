<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use Closure;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\PromotionsError;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who may read a store's marketing (promotions.md §3): `promotions.marketing.view` in that store — or
 * any of the three manage permissions, since whoever changes coupons, segments or gift levels sees
 * them. Each handler authorizes the permission this answers, then asks `open`. As `MarketingChange`
 * for the rest: a coupon or segment of a store the reader does not cover is not found, as an unknown
 * one; a store switched off is read only by whoever may switch stores — the panel offers off stores to
 * Super Admins alone (platform.md §9.10).
 */
final readonly class MarketingReader
{
    /** The view permission first: it names the job when nothing is held. */
    public const array PERMISSIONS = [
        PromotionsPermissions::VIEW,
        PromotionsPermissions::COUPON_MANAGE,
        PromotionsPermissions::SEGMENT_MANAGE,
        PromotionsPermissions::GIFT_MANAGE,
    ];

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * A store named by the reader, and the permission to authorize there.
     *
     * @return array{StoreId, string}
     *
     * @throws Unauthorized
     */
    public function store(string $storeId): array
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized(PromotionsPermissions::VIEW);
        }

        return [$store, $this->heldIn($store)];
    }

    /**
     * An existing item's store, which the reader must cover with one of the four permissions — one
     * they do not is answered as an unknown item; someone holding none of them anywhere is not allowed
     * — and the permission to authorize there.
     *
     * @param  Closure(): ?StoreId  $storeOf
     * @param  Closure(): PromotionsError  $notFound
     * @return array{StoreId, string}
     *
     * @throws Unauthorized|PromotionsError
     */
    public function covered(Closure $storeOf, Closure $notFound): array
    {
        $everywhere = false;
        $covered = [];

        foreach (self::PERMISSIONS as $permission) {
            $stores = $this->authorizer->storesWith($permission);
            $everywhere = $everywhere || $stores === null;

            foreach ($stores ?? [] as $store) {
                $covered[$store->value] = true;
            }
        }

        if (! $everywhere && $covered === []) {
            throw new Unauthorized(PromotionsPermissions::VIEW);
        }

        $store = $storeOf() ?? throw $notFound();

        if (! $everywhere && ! isset($covered[$store->value])) {
            throw $notFound();
        }

        return [$store, $this->heldIn($store)];
    }

    /**
     * After the permission: a store switched off only for whoever may switch stores.
     *
     * @throws Unauthorized
     */
    public function open(StoreId $store): void
    {
        $found = $this->platform->store($store);

        if ($found !== null && ! $found->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new Unauthorized(PromotionsPermissions::VIEW);
        }
    }

    /** The first of the four the reader holds in the store — the view permission when none. */
    private function heldIn(StoreId $store): string
    {
        foreach (self::PERMISSIONS as $permission) {
            $stores = $this->authorizer->storesWith($permission);

            if ($stores === null || in_array($store->value, array_map(static fn (StoreId $id): string => $id->value, $stores), true)) {
                return $permission;
            }
        }

        return PromotionsPermissions::VIEW;
    }
}

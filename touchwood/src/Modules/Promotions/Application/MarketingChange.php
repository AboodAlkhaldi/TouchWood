<?php

declare(strict_types=1);

namespace Modules\Promotions\Application;

use Closure;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Exception\GiftLevelNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\PromotionsError;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Domain\Repository\GiftLevels;
use Modules\Promotions\Domain\Repository\Segments;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every staff change to a store's coupons, segments and gift levels shares (promotions.md §1.8, §3, §7), as Pricing's
 * `PricingChange`:
 *
 * 1. **The permission in that store**, before anything is looked up — a malformed store id is no store
 *    the actor holds anything in.
 * 2. **A coupon, segment or gift level of a store the staff member does not cover is not found** — the
 *    same answer as an unknown one (§7) — while someone holding the job nowhere is simply not allowed.
 * 3. **A store that exists**, and **a store switched off** changed only by whoever may switch stores —
 *    Super Admins, to whom alone the panel offers off stores (§1.8).
 * 4. **Its own transaction** (a savepoint inside a caller's), the store's coupon lock first, then the
 *    audit entries the work answers — none when nothing changed — inside it.
 */
final readonly class MarketingChange
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private Coupons $coupons,
        private Segments $segments,
        private GiftLevels $levels,
        private ConnectionInterface $db,
    ) {}

    /**
     * The permission in the store — a new coupon's, or an existing one's (`couponStore`) — then the
     * store open to the actor.
     *
     * @throws Unauthorized|InvalidPromotionAttribute
     */
    public function authorize(string $permission, string $storeId): StoreDto
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized($permission);
        }

        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $found = $this->platform->store($store) ?? throw new InvalidPromotionAttribute('store', 'no such store');

        if (! $found->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new Unauthorized($permission);
        }

        return $found;
    }

    /**
     * An existing coupon's store, which the staff member must cover: one they do not is answered as
     * an unknown coupon (§7); someone holding the job nowhere is simply not allowed.
     *
     * @throws Unauthorized|CouponNotFound
     */
    public function couponStore(string $permission, string $couponId): string
    {
        return $this->covered($permission, fn (): ?StoreId => $this->coupons->storeOf($couponId), static fn (): CouponNotFound => new CouponNotFound);
    }

    /**
     * An existing segment's store, as `couponStore` (§7: `SegmentNotFound` for both).
     *
     * @throws Unauthorized|SegmentNotFound
     */
    public function segmentStore(string $permission, string $segmentId): string
    {
        return $this->covered($permission, fn (): ?StoreId => $this->segments->storeOf($segmentId), static fn (): SegmentNotFound => new SegmentNotFound);
    }

    /**
     * An existing gift level's store, as `couponStore` (§7: `GiftLevelNotFound` for both).
     *
     * @throws Unauthorized|GiftLevelNotFound
     */
    public function giftLevelStore(string $permission, string $levelId): string
    {
        return $this->covered($permission, fn (): ?StoreId => $this->levels->storeOf($levelId), static fn (): GiftLevelNotFound => new GiftLevelNotFound);
    }

    /**
     * @param  Closure(): ?StoreId  $storeOf
     * @param  Closure(): PromotionsError  $notFound
     */
    private function covered(string $permission, Closure $storeOf, Closure $notFound): string
    {
        $covered = $this->authorizer->storesWith($permission);

        if ($covered === []) {
            throw new Unauthorized($permission);
        }

        $store = $storeOf() ?? throw $notFound();

        if ($covered !== null && ! in_array($store->value, array_map(static fn (StoreId $id): string => $id->value, $covered), true)) {
            throw $notFound();
        }

        return $store->value;
    }

    /**
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work  answers its result and its audit entries
     * @return T
     */
    public function run(StoreDto $store, Closure $work): mixed
    {
        return $this->db->transaction(function () use ($store, $work): mixed {
            // One staff change of a store's marketing at a time: two new coupons with one code cannot
            // both pass the check (the unique index is the backstop), nor a segment be refreshed twice, nor
            // two gift levels with one amount both pass the clash check.
            $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['promotions:store:'.$store->id], false);
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }
}

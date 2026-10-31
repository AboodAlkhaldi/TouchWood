<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Delivery;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * What the commands of notices and staff messages share (ops.md §1.7, §1.8, §7): the staff member
 * writing, the store as a request names it, a time read in the store's time zone, and whether a store
 * - or one of its segments - has anyone to send to.
 */
final readonly class Writers
{
    public function __construct(
        private ActorContext $actors,
        private PlatformApi $platform,
        private AccessApi $access,
        private Authorizer $authorizer,
        private PromotionsApi $promotions,
    ) {}

    /**
     * The staff member acting: only staff write to customers.
     *
     * @throws Unauthorized
     */
    public function staffId(string $permission): string
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        return strtolower($actor->id);
    }

    /** @throws InvalidOpsInput */
    public function store(string $storeId): StoreDto
    {
        try {
            $store = $this->platform->store(StoreId::fromString(strtolower(trim($storeId))));
        } catch (InvalidArgumentException) {
            $store = null;
        }

        return $store ?? throw new InvalidOpsInput('store', 'a store');
    }

    /**
     * A date and time as the form gives it - "2026-11-01 09:30", or with a "T" - read in the store's
     * own time zone (§1.7). None: send now.
     *
     * @throws InvalidOpsInput
     */
    public static function sendAt(?string $value, StoreDto $store): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = str_replace('T', ' ', trim($value));

        try {
            $at = preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}\z/', $value) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d H:i', $value, $store->timezone)
                : null;
        } catch (Throwable) {
            $at = null;
        }

        // A date that does not exist (February 30th) rolls over; it is refused instead.
        if (! $at instanceof CarbonImmutable || $at->format('Y-m-d H:i') !== $value) {
            throw new InvalidOpsInput('send_at', 'a date and a time');
        }

        return $at->toDateTimeImmutable();
    }

    /**
     * Whether a message to one customer may reach them (owner, 2026-10-30): a customer registered in
     * a store where the writer holds `ops.message.send_one` **and** may view customers
     * (AccessApi::customerViewStores). Anyone else is as unknown, never "not yours".
     */
    public function mayWriteToOne(CustomerDto $customer): bool
    {
        if ($customer->anonymized) {
            return false;
        }

        $home = strtolower($customer->homeStoreId);

        foreach ([$this->authorizer->storesWith(OpsPermissions::MESSAGE_SEND_ONE), $this->access->customerViewStores()] as $stores) {
            if ($stores !== null && ! in_array($home, array_map(static fn (StoreId $store): string => $store->value, $stores), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A store with no customer at all is an empty audience (§7).
     *
     * @throws InvalidOpsInput
     */
    public function requireCustomers(StoreId $store): void
    {
        foreach (AccountType::cases() as $type) {
            if ($this->access->customerIdsInStore($store, $type, null, 1) !== []) {
                return;
            }
        }

        throw new InvalidOpsInput('audience', 'a store with customers');
    }

    /**
     * The audience of a notice (§1.7): every customer of the store, or one of **its** segments, read
     * through the store-scoped `PromotionsApi::segmentMembers` - another store's segment, or one that
     * does not exist, is refused there as Promotions' `SegmentNotFound`; a segment with no member is an
     * empty audience (§7).
     *
     * @return string|null the segment's id, in lowercase; none for every customer of the store
     *
     * @throws InvalidOpsInput
     */
    public function audience(StoreId $store, ?string $segmentId): ?string
    {
        if ($segmentId === null || trim($segmentId) === '') {
            $this->requireCustomers($store);

            return null;
        }

        $segmentId = strtolower(trim($segmentId));

        if ($this->promotions->segmentMembers($store, $segmentId, null, 1) === []) {
            throw new InvalidOpsInput('audience', 'a segment with customers');
        }

        return $segmentId;
    }
}

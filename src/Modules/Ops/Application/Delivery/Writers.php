<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Delivery;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccountType;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * What the commands of notices and staff messages share (ops.md §1.7, §1.8, §7): the staff member
 * writing, the store as a request names it, a time read in the store's time zone, and whether a store
 * has anyone to send to.
 */
final readonly class Writers
{
    public function __construct(
        private ActorContext $actors,
        private PlatformApi $platform,
        private AccessApi $access,
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
}

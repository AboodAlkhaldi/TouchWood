<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Platform\Public\Enums\HomePeriod;
use Shared\Domain\ValueObject\StoreId;

/**
 * Which stores a home card speaks for (platform.md §2.6), the store chosen on Home or every store - the admin
 * home's store switcher (frontend.md §2.2) - and **over which period** (dashboard.md §3.3, §6.1): the period
 * chosen on Home, read up to the moment the page asked (`at`), so every card of one page reads the same now.
 */
final readonly class HomeScope
{
    private function __construct(
        private ?StoreId $store,
        public HomePeriod $period,
        public DateTimeImmutable $at,
    ) {}

    public static function store(StoreId $store, HomePeriod $period = HomePeriod::OPENS_ON, ?DateTimeImmutable $at = null): self
    {
        return new self($store, $period, $at ?? CarbonImmutable::now());
    }

    public static function allStores(HomePeriod $period = HomePeriod::OPENS_ON, ?DateTimeImmutable $at = null): self
    {
        return new self(null, $period, $at ?? CarbonImmutable::now());
    }

    public function isAllStores(): bool
    {
        return $this->store === null;
    }

    /**
     * @return list<StoreId>|null the one store, or null for every store
     */
    public function stores(): ?array
    {
        return $this->store === null ? null : [$this->store];
    }

    /** The period and the one before it in a store's clock (its time zone). */
    public function range(string $timezone): HomeRange
    {
        return $this->period->range($this->at, new DateTimeZone($timezone));
    }
}

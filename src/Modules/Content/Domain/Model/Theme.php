<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;
use Modules\Content\Domain\ValueObject\Schedule;

/**
 * A theme (content.md §1.8): the 11 parts only. Its name per language; its start and end as typed (`Y-m-d H:i`) and,
 * per store it picks, that store's own moments (`$stores`, Q1 (a)); scheduled or a draft. `SCHEDULED`, `LIVE` and
 * `ENDED` are read per store from those moments and the clock (§4.1), never stored. `slug` is what the page's
 * `data-campaign` carries.
 */
final readonly class Theme
{
    /**
     * @param  array<string, array{0: DateTimeImmutable, 1: DateTimeImmutable}>  $stores  store id => [start, end], in store-id order
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $nameAr,
        public ?string $nameEn,
        public bool $isScheduled,
        public string $startsLocal,
        public string $endsLocal,
        public array $stores,
        public int $version,
        public ?string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** Its schedule in one store, or null for a store it does not pick. */
    public function scheduleIn(string $storeId): ?Schedule
    {
        $moments = $this->stores[strtolower($storeId)] ?? null;

        return $moments === null ? null : new Schedule($this->isScheduled, $moments[0], $moments[1]);
    }

    /** Ended everywhere: history (§1.1 rule 11). A draft never is. */
    public function hasEnded(DateTimeImmutable $now): bool
    {
        if (! $this->isScheduled || $this->stores === []) {
            return false;
        }

        foreach ($this->stores as [, $ends]) {
            if ($ends > $now) {
                return false;
            }
        }

        return true;
    }

    /** Live or ended somewhere. */
    public function hasStarted(DateTimeImmutable $now): bool
    {
        if (! $this->isScheduled) {
            return false;
        }

        foreach ($this->stores as [$starts]) {
            if ($starts <= $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        return [
            'slug' => $this->slug,
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'is_scheduled' => $this->isScheduled,
            'starts_at_local' => $this->startsLocal,
            'ends_at_local' => $this->endsLocal,
            'stores' => implode(',', array_keys($this->stores)),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use DateTimeImmutable;
use Modules\Content\Domain\Exception\SlugTaken;
use Modules\Content\Domain\Exception\StaleEdit;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\SlugHistory;
use Modules\Platform\Public\Contracts\StaffNames;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * Two checks every long edit makes inside its transaction, after its row is locked:
 *
 * - **The version** (content.md §1.1 rule 12): a save over a newer version than the form read is refused with who
 *   saved it and when, in the store's zone, and what was typed stays on the screen (`StaleEdit`).
 * - **The address** (§1.1 rule 15): under the store's address lock, an address another item holds - now or
 *   before - is refused (`SlugTaken`); the item's own are kept in its history, so an old one still forwards.
 */
final readonly class EditCheck
{
    public function __construct(
        private StaffNames $names,
        private SlugHistory $history,
        private ContentLocks $locks,
    ) {}

    /**
     * @throws StaleEdit
     */
    public function version(int $read, int $current, ?string $by, ?DateTimeImmutable $at, StoreDto $store): void
    {
        if ($read === $current) {
            return;
        }

        $name = $by === null ? null : ($this->names->forReader([$by])[strtolower($by)] ?? null)?->name;

        throw new StaleEdit($name ?? '-', $at === null ? '-' : Moments::shown($at, $store));
    }

    /**
     * Claims the item's addresses: each refused when another item holds it, then kept as the item's.
     *
     * @param  array<string, string>  $slugs  language => address
     *
     * @throws SlugTaken
     */
    public function addresses(string $storeId, string $kind, string $itemId, array $slugs): void
    {
        $this->locks->lock($storeId, ContentLocks::SLUGS);

        foreach ($slugs as $locale => $slug) {
            $holder = $this->history->holder($storeId, $kind, $locale, $slug);

            if ($holder !== null && $holder !== strtolower($itemId)) {
                throw new SlugTaken($locale, $slug);
            }
        }

        foreach ($slugs as $locale => $slug) {
            $this->history->record($storeId, $kind, $itemId, $locale, $slug);
        }
    }
}

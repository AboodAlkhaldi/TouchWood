<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListStores;

use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * The stores this person may see, and which of them they may change (frontend.md §3.5, E1).
 *
 * "Each screen shows only the stores in the person's scope": somebody who manages one store opens
 * this screen and sees that store, not every store with most of them refusing. The screen is told
 * nothing about permissions and decides nothing; it is handed the stores and a flag per store.
 *
 * Viewing and changing are asked separately, because they are separate permissions and a person may
 * well hold the first without the second - a manager who reads every store's settings and edits
 * none of them.
 */
final readonly class ListStoresHandler
{
    public const string PERMISSION = PlatformPermissions::STORE_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StoreDirectory $directory,
    ) {}

    /**
     * @return list<StoreSummary>
     *
     * @throws Unauthorized when they may not see any store at all
     */
    public function handle(ListStores $query): array
    {
        $visible = $this->authorizer->storesWith(self::PERMISSION);

        // Nowhere at all: the screen does not exist for them, and Access's own refusal says so
        // rather than an empty list that reads as "there are no stores".
        if ($visible === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $editable = $this->authorizer->storesWith(PlatformPermissions::STORE_UPDATE);

        // Null from Access means every store, now and for any store added later.
        $maySee = $visible === null ? null : array_map(static fn ($store): string => $store->value, $visible);
        $mayEdit = $editable === null ? null : array_map(static fn ($store): string => $store->value, $editable);

        $summaries = [];

        foreach ($this->directory->stores() as $store) {
            if ($maySee !== null && ! in_array($store->id, $maySee, true)) {
                continue;
            }

            $summaries[] = $this->summary($store, $mayEdit === null || in_array($store->id, $mayEdit, true), $query->locale);
        }

        return $summaries;
    }

    private function summary(StoreDto $store, bool $editable, string $locale): StoreSummary
    {
        return new StoreSummary(
            $store->id,
            $store->code,
            $store->name->ar,
            $store->name->en,
            $store->countryCode,
            $store->currencyCode,
            $store->currencySymbol($locale),
            $store->taxRateBasisPoints,
            $store->timezone,
            $store->position,
            $editable,
        );
    }
}

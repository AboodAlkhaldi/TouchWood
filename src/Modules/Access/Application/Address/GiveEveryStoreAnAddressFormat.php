<?php

declare(strict_types=1);

namespace Modules\Access\Application\Address;

use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * Every store that has no address form gets the starting one (amendment 41). It runs when Access's
 * schema is migrated, so the stores an installation already has can take addresses at once, and it
 * may be run again safely: a store that has a format — the standard one or one staff changed — is
 * left exactly as it is.
 *
 * A store opened afterwards is served by WriteStartingAddressFormat, on Platform's `StoreCreated`.
 */
final readonly class GiveEveryStoreAnAddressFormat
{
    public function __construct(
        private PlatformApi $platform,
        private StoreAddressFormatRepository $formats,
    ) {}

    /**
     * @return int how many stores were given one
     */
    public function run(): int
    {
        $written = 0;

        foreach ($this->platform->stores() as $store) {
            if ($this->formats->existsFor($store->id)) {
                continue;
            }

            $this->formats->save(StartingAddressFormat::forStore($store->id));
            $written++;
        }

        return $written;
    }
}

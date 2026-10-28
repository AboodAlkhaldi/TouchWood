<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Repository;

use Modules\Platform\Domain\Exception\CountryShippedByAnotherStore;
use Shared\Domain\ValueObject\StoreId;

/**
 * Each store's ship-to countries (platform.md, stage 7 (b)): a country in one store at most.
 */
interface StoreCountryRepository
{
    /**
     * The store that ships to the country, its row locked until the transaction ends.
     */
    public function storeOf(string $countryCode): ?StoreId;

    /**
     * @throws CountryShippedByAnotherStore when another store took the country first
     */
    public function add(StoreId $store, string $countryCode): void;

    public function remove(StoreId $store, string $countryCode): void;
}

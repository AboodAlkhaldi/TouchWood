<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Repository;

use Modules\Platform\Domain\Model\AddressCountry;

/**
 * The address countries. Every change to a country's places or to who ships there locks the
 * country's row first (`byCode`), so changes to one country never interleave: one lock order,
 * country before store row before place.
 */
interface AddressCountryRepository
{
    /**
     * Locked until the transaction ends.
     */
    public function byCode(string $code): ?AddressCountry;

    /**
     * @return bool false when the country is already there, possibly added at the same moment by
     *              someone else; the caller then locks it with byCode()
     */
    public function add(AddressCountry $country): bool;

    public function update(AddressCountry $country): void;

    /**
     * Whether the country has a city in use - neither it nor its region retired: what "has its
     * place list" means for a ship-to country (stage 7 step 1, Q5).
     */
    public function hasLiveCity(string $code): bool;
}

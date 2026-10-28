<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Repository;

use Modules\Platform\Domain\Model\Place;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\Enums\PlaceKind;

interface PlaceRepository
{
    public function nextId(): string;

    /**
     * The place's country, read without a lock: a change locks the country's row first, then
     * the place (AddressCountryRepository's lock order).
     */
    public function countryOf(string $id): ?string;

    /**
     * Locked until the transaction ends.
     */
    public function byId(string $id): ?Place;

    public function add(Place $place): void;

    public function update(Place $place): void;

    /**
     * The name, in either language, of another place of this kind under this parent that is
     * called the same (case aside), retired ones included; null when there is none.
     */
    public function nameTaken(string $countryCode, PlaceKind $kind, ?string $parentId, TranslatedText $name, ?string $exceptId = null): ?string;
}

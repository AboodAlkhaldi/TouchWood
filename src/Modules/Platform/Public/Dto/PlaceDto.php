<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Modules\Platform\Public\Enums\PlaceKind;

/**
 * A region, a city or a district (platform.md, stage 7 (a)). `regionId` and `cityId` name the
 * places above it (a region has neither, a city has its region). `retired` is true when the place
 * or anything above it - its country included - is retired: it is then in no picker, yet still
 * read by id for the addresses and prices that already name it (shipping.md §9.3 #4).
 */
final readonly class PlaceDto
{
    public function __construct(
        public string $id,
        public string $countryCode,
        public PlaceKind $kind,
        public ?string $regionId,
        public ?string $cityId,
        public TranslatedTextDto $name,
        public bool $retired,
    ) {}
}

<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * The three levels of a country's place list (platform.md, stage 7 (a)): a region holds cities, a
 * city may hold districts.
 */
enum PlaceKind: string
{
    case Region = 'REGION';
    case City = 'CITY';
    case District = 'DISTRICT';
}

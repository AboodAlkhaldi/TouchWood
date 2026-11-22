<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * How a line under the shop's header reads (access.md amendment 50): something to do, something to
 * wait for, or something that went wrong.
 */
enum ShopperLineTone: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Bad = 'bad';
}

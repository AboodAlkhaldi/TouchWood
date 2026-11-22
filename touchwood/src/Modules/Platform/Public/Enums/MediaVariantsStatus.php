<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

enum MediaVariantsStatus: string
{
    case Pending = 'PENDING';
    case Ready = 'READY';
    case Failed = 'FAILED';
}

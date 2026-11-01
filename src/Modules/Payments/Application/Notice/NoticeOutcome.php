<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Notice;

enum NoticeOutcome
{
    case Accepted;
    case Refused;
}

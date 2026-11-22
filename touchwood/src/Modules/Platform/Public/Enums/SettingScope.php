<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

enum SettingScope: string
{
    case Global = 'GLOBAL';
    case Store = 'STORE';
}

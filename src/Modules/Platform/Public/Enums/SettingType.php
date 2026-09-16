<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * The PHP type a setting's value must have. Checked strictly — "5" is not an integer and 1 is
 * not a boolean — before any validation rule runs, because Laravel's rules skip empty strings
 * and accept numeric text.
 */
enum SettingType: string
{
    case Integer = 'INTEGER';
    case Boolean = 'BOOLEAN';
    case Text = 'TEXT';
    case List = 'LIST';
}

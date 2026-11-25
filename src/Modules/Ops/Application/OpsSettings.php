<?php

declare(strict_types=1);

namespace Modules\Ops\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Ops' store settings, on Platform's Settings page: the working days the shipped message by WhatsApp promises
 * (ops.md §9.10 point 4) - "4-7" or "3", empty until a person writes it, and the shipped message is not sent while it is.
 */
final class OpsSettings
{
    public const string DELIVERY_DAYS = 'ops.whatsapp.delivery_days';

    /** One or two numbers of one or two digits, joined by a dash. */
    public const string DAYS_PATTERN = '/\A\d{1,2}(-\d{1,2})?\z/';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::DELIVERY_DAYS, SettingScope::Store, SettingType::Text, ['max:5', 'regex:'.self::DAYS_PATTERN], '', OpsPermissions::MESSAGE_SEND_MANY, mayBeEmpty: true),
        ];
    }
}

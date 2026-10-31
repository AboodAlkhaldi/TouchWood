<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Modules\Platform\Public\PlatformPermissions;

/**
 * Payments' store settings (payments.md §5): the timers of a payment (§1.7) - Super Admins' - and the
 * hours staff have to check a bank transfer (§1.9), changed by whoever changes the store's settings
 * (owner, 2026-10-29, gap 8.3).
 */
final class PaymentsSettings
{
    /** The customer's time on the bank's page, in minutes (owner, 2026-10-28). */
    public const string WINDOW_MINUTES = 'payments.window_minutes';

    /** The wait for an answer after the window, in minutes (owner, 2026-10-28). */
    public const string WAIT_MINUTES = 'payments.wait_minutes';

    /**
     * The hold limit: a try still unanswered this long after Pay is released (owner, 2026-10-29, §9.8 #1;
     * gap 8.3 (a)). Always longer than the window and the wait together (`TimersGuard`).
     */
    public const string HOLD_MINUTES = 'payments.hold_minutes';

    /** The hours staff have to check a transfer from the receipt's upload (owner, 2026-10-27). */
    public const string TRANSFER_CHECK_HOURS = 'payments.transfer.check_hours';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::WINDOW_MINUTES, SettingScope::Store, SettingType::Integer, ['min:1', 'max:30'], 5, PaymentsPermissions::GATEWAY_MANAGE),
            new SettingDefinitionDto(self::WAIT_MINUTES, SettingScope::Store, SettingType::Integer, ['min:1', 'max:10'], 2, PaymentsPermissions::GATEWAY_MANAGE),
            new SettingDefinitionDto(self::HOLD_MINUTES, SettingScope::Store, SettingType::Integer, ['min:10', 'max:60'], 15, PaymentsPermissions::GATEWAY_MANAGE),
            new SettingDefinitionDto(self::TRANSFER_CHECK_HOURS, SettingScope::Store, SettingType::Integer, ['min:1', 'max:720'], 72, PlatformPermissions::SETTINGS_UPDATE),
        ];
    }
}

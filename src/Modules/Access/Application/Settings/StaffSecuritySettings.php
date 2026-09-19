<?php

declare(strict_types=1);

namespace Modules\Access\Application\Settings;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * The staff security numbers are settings, so they change without a deploy; staff settings are
 * global (spec §1.8). Defaults are the owner's decisions of 2026-09-18.
 */
final readonly class StaffSecuritySettings
{
    public const string PASSWORD_MIN_LENGTH = 'access.staff.password_min_length';

    public const string INVITATION_HOURS = 'access.staff.invitation_hours';

    /** A Super Admin's invitation; unaccepted by then, the account is cancelled (amendment 30). */
    public const string SUPER_ADMIN_INVITATION_HOURS = 'access.staff.super_admin_invitation_hours';

    public const string EMAIL_CHANGE_HOURS = 'access.staff.email_change_hours';

    public const string CODE_LENGTH = 'access.staff.sms_code_length';

    public const string CODE_MINUTES = 'access.staff.sms_code_minutes';

    public const string CODE_RESEND_SECONDS = 'access.staff.sms_resend_seconds';

    public const string CODES_PER_HOUR = 'access.staff.sms_codes_per_hour';

    public const string CODE_ATTEMPTS = 'access.staff.sms_code_attempts';

    public function __construct(
        private PlatformApi $platform,
    ) {}

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        $setting = fn (string $key, int $default, int $min, int $max): SettingDefinitionDto => new SettingDefinitionDto(
            $key, SettingScope::Global, SettingType::Integer, ["min:{$min}", "max:{$max}"], $default, AccessPermissions::SETTINGS_UPDATE,
        );

        return [
            $setting(self::PASSWORD_MIN_LENGTH, 12, 8, 128),
            $setting(self::INVITATION_HOURS, 72, 1, 720),
            $setting(self::SUPER_ADMIN_INVITATION_HOURS, 24, 1, 720),
            $setting(self::EMAIL_CHANGE_HOURS, 72, 1, 720),
            $setting(self::CODE_LENGTH, 6, 4, 8),
            $setting(self::CODE_MINUTES, 5, 1, 60),
            $setting(self::CODE_RESEND_SECONDS, 60, 0, 3600),
            $setting(self::CODES_PER_HOUR, 3, 1, 100),
            $setting(self::CODE_ATTEMPTS, 5, 1, 20),
        ];
    }

    public function passwordMinLength(): int
    {
        return $this->int(self::PASSWORD_MIN_LENGTH);
    }

    public function invitationHours(): int
    {
        return $this->int(self::INVITATION_HOURS);
    }

    public function superAdminInvitationHours(): int
    {
        return $this->int(self::SUPER_ADMIN_INVITATION_HOURS);
    }

    public function emailChangeHours(): int
    {
        return $this->int(self::EMAIL_CHANGE_HOURS);
    }

    public function codeLength(): int
    {
        return $this->int(self::CODE_LENGTH);
    }

    public function codeMinutes(): int
    {
        return $this->int(self::CODE_MINUTES);
    }

    public function codeResendSeconds(): int
    {
        return $this->int(self::CODE_RESEND_SECONDS);
    }

    public function codesPerHour(): int
    {
        return $this->int(self::CODES_PER_HOUR);
    }

    public function codeAttempts(): int
    {
        return $this->int(self::CODE_ATTEMPTS);
    }

    private function int(string $key): int
    {
        return $this->platform->setting($key)->int();
    }
}

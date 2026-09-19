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

    /** Wrong passwords for one account before it is locked (spec §1.8). */
    public const string LOCKOUT_ATTEMPTS = 'access.staff.lockout_attempts';

    public const string LOCKOUT_MINUTES = 'access.staff.lockout_minutes';

    /** Wrong passwords from one IP address, across accounts, before it waits (amendment 31). */
    public const string IP_ATTEMPTS = 'access.staff.ip_attempts';

    public const string IP_MINUTES = 'access.staff.ip_minutes';

    public const string SESSION_IDLE_MINUTES = 'access.staff.session_idle_minutes';

    public const string SESSION_MAX_HOURS = 'access.staff.session_max_hours';

    public const string TRUSTED_BROWSER_DAYS = 'access.staff.trusted_browser_days';

    public const string PASSWORD_RESET_MINUTES = 'access.staff.password_reset_minutes';

    /** Reset emails to one account an hour, so nobody can flood an inbox. */
    public const string PASSWORD_RESETS_PER_HOUR = 'access.staff.password_reset_hourly_limit';

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
            $setting(self::LOCKOUT_ATTEMPTS, 5, 3, 20),
            $setting(self::LOCKOUT_MINUTES, 15, 1, 1440),
            $setting(self::IP_ATTEMPTS, 10, 3, 100),
            $setting(self::IP_MINUTES, 15, 1, 1440),
            $setting(self::SESSION_IDLE_MINUTES, 30, 5, 720),
            $setting(self::SESSION_MAX_HOURS, 12, 1, 72),
            $setting(self::TRUSTED_BROWSER_DAYS, 30, 1, 90),
            $setting(self::PASSWORD_RESET_MINUTES, 30, 5, 1440),
            $setting(self::PASSWORD_RESETS_PER_HOUR, 3, 1, 20),
        ];
    }

    public function lockoutAttempts(): int
    {
        return $this->int(self::LOCKOUT_ATTEMPTS);
    }

    public function lockoutMinutes(): int
    {
        return $this->int(self::LOCKOUT_MINUTES);
    }

    public function ipAttempts(): int
    {
        return $this->int(self::IP_ATTEMPTS);
    }

    public function ipMinutes(): int
    {
        return $this->int(self::IP_MINUTES);
    }

    public function sessionIdleMinutes(): int
    {
        return $this->int(self::SESSION_IDLE_MINUTES);
    }

    public function sessionMaxHours(): int
    {
        return $this->int(self::SESSION_MAX_HOURS);
    }

    public function trustedBrowserDays(): int
    {
        return $this->int(self::TRUSTED_BROWSER_DAYS);
    }

    public function passwordResetMinutes(): int
    {
        return $this->int(self::PASSWORD_RESET_MINUTES);
    }

    public function passwordResetsPerHour(): int
    {
        return $this->int(self::PASSWORD_RESETS_PER_HOUR);
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

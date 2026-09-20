<?php

declare(strict_types=1);

namespace Modules\Access\Application\Settings;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\LockoutLimits;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Shared\Application\StoreContext;

/**
 * The customer security numbers are settings, and customer settings are **per store** (spec §1.8,
 * handoff §7.7): each store is its own market, with its own terms and its own traffic. Read for the
 * store the request is in.
 */
final readonly class CustomerSecuritySettings implements LockoutLimits
{
    public const string PASSWORD_MIN_LENGTH = 'access.customer.password_min_length';

    /** How long the email verification link works (spec §1.2). */
    public const string EMAIL_VERIFICATION_HOURS = 'access.customer.email_verification_hours';

    /** The version of this store's terms and privacy policy, recorded at registration (amendment 37). */
    public const string TERMS_VERSION = 'access.customer.terms_version';

    public const string CODE_LENGTH = 'access.customer.sms_code_length';

    public const string CODE_MINUTES = 'access.customer.sms_code_minutes';

    public const string CODE_RESEND_SECONDS = 'access.customer.sms_resend_seconds';

    public const string CODES_PER_HOUR = 'access.customer.sms_codes_per_hour';

    public const string CODE_ATTEMPTS = 'access.customer.sms_code_attempts';

    /** Wrong passwords for one account before it is locked (spec §1.8). */
    public const string LOCKOUT_ATTEMPTS = 'access.customer.lockout_attempts';

    public const string LOCKOUT_MINUTES = 'access.customer.lockout_minutes';

    /** Wrong passwords from one address, across accounts (amendment 37: 10 in 15 minutes). */
    public const string IP_ATTEMPTS = 'access.customer.ip_attempts';

    public const string IP_MINUTES = 'access.customer.ip_minutes';

    /** Without "remember me": how long a customer may be idle before the session ends. */
    public const string SESSION_IDLE_MINUTES = 'access.customer.session_idle_minutes';

    /** With "remember me": how long they stay signed in. */
    public const string REMEMBER_DAYS = 'access.customer.remember_days';

    public const string PASSWORD_RESET_MINUTES = 'access.customer.password_reset_minutes';

    /** Reset emails to one account an hour, so nobody can flood an inbox. */
    public const string PASSWORD_RESETS_PER_HOUR = 'access.customer.password_reset_hourly_limit';

    public function __construct(
        private PlatformApi $platform,
        private StoreContext $stores,
    ) {}

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        $number = fn (string $key, int $default, int $min, int $max): SettingDefinitionDto => new SettingDefinitionDto(
            $key, SettingScope::Store, SettingType::Integer, ["min:{$min}", "max:{$max}"], $default, AccessPermissions::SETTINGS_UPDATE,
        );

        return [
            $number(self::PASSWORD_MIN_LENGTH, 8, 8, 128),
            $number(self::EMAIL_VERIFICATION_HOURS, 24, 1, 720),
            $number(self::CODE_LENGTH, 6, 4, 8),
            $number(self::CODE_MINUTES, 5, 1, 60),
            $number(self::CODE_RESEND_SECONDS, 60, 0, 3600),
            $number(self::CODES_PER_HOUR, 3, 1, 100),
            $number(self::CODE_ATTEMPTS, 5, 1, 20),
            $number(self::LOCKOUT_ATTEMPTS, 5, 3, 20),
            $number(self::LOCKOUT_MINUTES, 15, 1, 1440),
            $number(self::IP_ATTEMPTS, 10, 3, 100),
            $number(self::IP_MINUTES, 15, 1, 1440),
            $number(self::SESSION_IDLE_MINUTES, 120, 5, 720),
            $number(self::REMEMBER_DAYS, 30, 1, 365),
            $number(self::PASSWORD_RESET_MINUTES, 60, 5, 1440),
            $number(self::PASSWORD_RESETS_PER_HOUR, 3, 1, 20),
            new SettingDefinitionDto(
                self::TERMS_VERSION, SettingScope::Store, SettingType::Text, ['max:32'], '2026-01', AccessPermissions::SETTINGS_UPDATE,
            ),
        ];
    }

    public function passwordMinLength(): int
    {
        return $this->int(self::PASSWORD_MIN_LENGTH);
    }

    public function emailVerificationHours(): int
    {
        return $this->int(self::EMAIL_VERIFICATION_HOURS);
    }

    public function termsVersion(): string
    {
        return $this->platform->setting(self::TERMS_VERSION, $this->stores->current())->string();
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

    public function rememberDays(): int
    {
        return $this->int(self::REMEMBER_DAYS);
    }

    public function passwordResetMinutes(): int
    {
        return $this->int(self::PASSWORD_RESET_MINUTES);
    }

    public function passwordResetsPerHour(): int
    {
        return $this->int(self::PASSWORD_RESETS_PER_HOUR);
    }

    private function int(string $key): int
    {
        return $this->platform->setting($key, $this->stores->current())->int();
    }
}

<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Audit;

use Modules\Ops\Domain\Model\SmsCountry;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Audit entries for the SMS countries (access.md amendment 90): a Super Admin's change of where codes
 * can go. None of it is personal data, so every value is kept.
 */
final class SmsCountryAudit
{
    public static function added(SmsCountry $country): AuditEntryDto
    {
        return new AuditEntryDto('ops.sms_country.added', 'ops.sms_country', $country->countryCode, null, AuditChanges::none()
            ->changed('country', null, $country->countryCode)
            ->changed('calling_code', null, $country->callingCode())
            ->changed('provider', null, $country->provider()));
    }

    public static function providerChanged(SmsCountry $country, string $before): AuditEntryDto
    {
        return new AuditEntryDto('ops.sms_country.provider_changed', 'ops.sms_country', $country->countryCode, null, AuditChanges::none()
            ->changed('provider', $before, $country->provider()));
    }

    public static function callingCodeChanged(SmsCountry $country, string $before): AuditEntryDto
    {
        return new AuditEntryDto('ops.sms_country.calling_code_changed', 'ops.sms_country', $country->countryCode, null, AuditChanges::none()
            ->changed('calling_code', $before, $country->callingCode()));
    }

    public static function removed(SmsCountry $country): AuditEntryDto
    {
        return new AuditEntryDto('ops.sms_country.removed', 'ops.sms_country', $country->countryCode, null, AuditChanges::none()
            ->changed('country', $country->countryCode, null)
            ->changed('calling_code', $country->callingCode(), null)
            ->changed('provider', $country->provider(), null));
    }
}

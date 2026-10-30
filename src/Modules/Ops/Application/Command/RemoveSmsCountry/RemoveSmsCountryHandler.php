<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\RemoveSmsCountry;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Audit\SmsCountryAudit;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\SmsCountryNotFound;
use Modules\Ops\Domain\Model\SmsCountry;
use Modules\Ops\Domain\Repository\SmsCountries;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Allowed even while saved phones are of that country (owner, 2026-10-29): from then on no code goes
 * to them. A staff member's phone is changed by an admin; a Super Admin enters a new number at their
 * next sign-in; a customer cannot order until they change theirs (access.md amendment 90).
 */
final readonly class RemoveSmsCountryHandler
{
    public const string PERMISSION = OpsPermissions::SMS_COUNTRIES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private SmsCountries $countries,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws InvalidOpsInput|SmsCountryNotFound
     */
    public function handle(RemoveSmsCountry $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = SmsCountry::countryCode($command->countryCode);

        $this->db->transaction(function () use ($code): void {
            $this->countries->lock();
            $country = $this->countries->byCode($code) ?? throw new SmsCountryNotFound($code);

            $this->countries->remove($country->countryCode);
            $this->platform->recordAudit(SmsCountryAudit::removed($country));
        }, 3);
    }
}

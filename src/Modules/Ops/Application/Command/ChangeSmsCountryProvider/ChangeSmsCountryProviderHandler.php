<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\ChangeSmsCountryProvider;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Audit\SmsCountryAudit;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\SmsCountryNotFound;
use Modules\Ops\Domain\Model\SmsCountry;
use Modules\Ops\Domain\Repository\SmsCountries;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The next SMS to that country's phones goes through the new provider; the same provider again
 * changes nothing and is not audited.
 */
final readonly class ChangeSmsCountryProviderHandler
{
    public const string PERMISSION = OpsPermissions::SMS_COUNTRIES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private SmsCountries $countries,
        private SmsSenders $senders,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws InvalidOpsInput|SmsCountryNotFound
     */
    public function handle(ChangeSmsCountryProvider $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = SmsCountry::countryCode($command->countryCode);

        if (! in_array(trim($command->provider), $this->senders->names(), true)) {
            throw new InvalidOpsInput('provider', 'not a provider Ops has');
        }

        $this->db->transaction(function () use ($code, $command): void {
            $this->countries->lock();
            $country = $this->countries->byCode($code) ?? throw new SmsCountryNotFound($code);
            $before = $country->provider();
            $country->changeProvider($command->provider);

            if ($country->pullChanges() === []) {
                return;
            }

            $this->countries->update($country);
            $this->platform->recordAudit(SmsCountryAudit::providerChanged($country, $before));
        }, 3);
    }
}

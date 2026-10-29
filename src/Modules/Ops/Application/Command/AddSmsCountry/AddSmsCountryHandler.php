<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\AddSmsCountry;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Audit\SmsCountryAudit;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\CallingCodeTaken;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\SmsCountryTaken;
use Modules\Ops\Domain\Model\SmsCountry;
use Modules\Ops\Domain\Repository\SmsCountries;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A country already on the list is refused, as is a calling code another country has: its provider
 * or code is changed instead. The provider is one Ops has built - only `log` for now (owner,
 * 2026-10-29).
 */
final readonly class AddSmsCountryHandler
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
     * @throws InvalidOpsInput|SmsCountryTaken|CallingCodeTaken
     */
    public function handle(AddSmsCountry $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $country = SmsCountry::add($command->countryCode, $command->callingCode, $command->provider);

        if (! in_array($country->provider(), $this->senders->names(), true)) {
            throw new InvalidOpsInput('provider', 'not a provider Ops has');
        }

        $this->db->transaction(function () use ($country): void {
            $this->countries->lock();

            if ($this->countries->byCode($country->countryCode) !== null) {
                throw new SmsCountryTaken($country->countryCode);
            }

            $holder = $this->countries->byCallingCode($country->callingCode());

            if ($holder !== null) {
                throw new CallingCodeTaken($country->callingCode(), $holder->countryCode);
            }

            $this->countries->add($country);
            $this->platform->recordAudit(SmsCountryAudit::added($country));
        }, 3);
    }
}

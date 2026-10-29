<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\ChangeSmsCountryCallingCode;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Audit\SmsCountryAudit;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\CallingCodeTaken;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\SmsCountryNotFound;
use Modules\Ops\Domain\Model\SmsCountry;
use Modules\Ops\Domain\Repository\SmsCountries;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Refused when another listed country has the code. From then on phones are matched by the new code:
 * a saved number the old code matched and the new one does not is treated as unlisted (amendment 87).
 */
final readonly class ChangeSmsCountryCallingCodeHandler
{
    public const string PERMISSION = OpsPermissions::SMS_COUNTRIES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private SmsCountries $countries,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws InvalidOpsInput|SmsCountryNotFound|CallingCodeTaken
     */
    public function handle(ChangeSmsCountryCallingCode $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = SmsCountry::countryCode($command->countryCode);

        $this->db->transaction(function () use ($code, $command): void {
            $this->countries->lock();
            $country = $this->countries->byCode($code) ?? throw new SmsCountryNotFound($code);
            $before = $country->callingCode();
            $country->changeCallingCode($command->callingCode);

            if ($country->pullChanges() === []) {
                return;
            }

            $holder = $this->countries->byCallingCode($country->callingCode());

            if ($holder !== null) {
                throw new CallingCodeTaken($country->callingCode(), $holder->countryCode);
            }

            $this->countries->update($country);
            $this->platform->recordAudit(SmsCountryAudit::callingCodeChanged($country, $before));
        }, 3);
    }
}

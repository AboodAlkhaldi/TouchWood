<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetCustomerPassword;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\CustomerTokenRepository;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The link chooses a new password and then dies (spec §1.8). Every session of that account ends —
 * the session version moves on — so a stolen session cannot outlive the reset.
 */
final readonly class ResetCustomerPasswordHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_RESET_PASSWORD;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
        private CustomerTokenRepository $tokens,
        private PasswordPolicy $passwords,
        private CustomerSecuritySettings $settings,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidOrExpiredLink
     */
    public function handle(ResetCustomerPassword $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // A dead link is refused before the slow part: the breach service and the hasher.
        $early = $this->tokens->passwordResetByToken(SecretTokens::hash($command->token));

        if ($early === null || $early->isExpired(CarbonImmutable::now())) {
            throw new InvalidOrExpiredLink;
        }

        $passwordHash = $this->passwords->hashNew($command->password, $this->settings->passwordMinLength());

        $this->db->transaction(function () use ($command, $passwordHash): void {
            $reset = $this->tokens->passwordResetByToken(SecretTokens::hash($command->token));

            if ($reset === null || $reset->isExpired(CarbonImmutable::now())) {
                throw new InvalidOrExpiredLink;
            }

            $customer = $this->customers->byId($reset->customerId);

            if ($customer === null || $customer->status() !== CustomerStatus::Active) {
                throw new InvalidOrExpiredLink;
            }

            $before = clone $customer;
            $customer->changePassword($passwordHash);
            $this->customers->update($customer);
            $this->tokens->deletePasswordReset($customer->id());
            $this->platform->recordAudit(CustomerAudit::updated('access.customer.password_reset', $before, $customer, $customer->pullChanges()));
        }, 3);
    }
}

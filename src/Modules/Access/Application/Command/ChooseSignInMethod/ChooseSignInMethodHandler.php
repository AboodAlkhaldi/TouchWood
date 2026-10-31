<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChooseSignInMethod;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\StaffSignInMethods;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\SignInMethod;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * With both methods ticked, each staff member chooses theirs (amendment 91, decision 1); with one, there
 * is nothing to choose. SMS keeps an app set up, unused. The app, with none set up, is set up right away
 * - this session reaches only the setup until then - and is used once its first code is right. Audited.
 * No password is asked (decision 6 does not list it; my assumption (iv)).
 */
final readonly class ChooseSignInMethodHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffAuthenticatorRepository $authenticators,
        private StaffSignInMethods $methods,
        private StaffSessions $sessions,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @return bool whether an app must be set up now
     *
     * @throws InvalidAccessAttribute
     */
    public function handle(ChooseSignInMethod $command): bool
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $method = SignInMethod::tryFrom($command->method) ?? throw new InvalidAccessAttribute('method', 'SMS or AUTHENTICATOR');

        if (! $this->methods->bothEnabled()) {
            throw new InvalidAccessAttribute('method', 'only one sign-in method is on, so there is nothing to choose');
        }

        $mustSetUp = $this->db->transaction(function () use ($staffId, $method): bool {
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);
            $app = $this->authenticators->forStaff($staffId, lock: true);
            $hasApp = $app !== null && $app->isConfirmed();

            if ($hasApp) {
                $this->authenticators->setInUse($staffId, $method === SignInMethod::Authenticator);
            }

            $this->platform->recordAudit(StaffAudit::event('access.staff_user.sign_in_method_chosen', $staff, ['method' => $method->value]));

            return $method === SignInMethod::Authenticator && ! $hasApp;
        }, 3);

        $this->sessions->requireAuthenticatorSetup($mustSetUp);

        return $mustSetUp;
    }
}

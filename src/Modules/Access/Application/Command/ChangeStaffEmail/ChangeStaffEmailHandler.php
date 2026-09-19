<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeStaffEmail;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffLinks;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;

final readonly class ChangeStaffEmailHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private StaffTokenRepository $tokens,
        private StaffSecuritySettings $settings,
        private SecurityMessages $messages,
        private StaffLinks $links,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(ChangeStaffEmail $command): void
    {
        $this->rules->requireSomewhere(self::PERMISSION);
        $email = EmailAddress::of($command->newEmail);

        $this->db->transaction(function () use ($command, $email): void {
            $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);

            foreach ($this->rules->scopesFor($this->assignments->byStaff($target->id())?->staffStores()) as $scope) {
                $this->authorizer->authorize(self::PERMISSION, $scope);
            }

            $author = $this->rules->author();

            // A Super Admin changes their own; anyone else's needs an admin who manages them.
            if (! ($target->isSuperAdmin() && $author->staffId === $target->id())) {
                $this->rules->requireManageable($author, $target, $this->grants->forStaff($target->id()));
            }

            if ($target->email()->sameAs($email) && $target->email()->value === $email->value) {
                throw new InvalidAccessAttribute('email', 'the same as the current one');
            }

            if ($this->staff->emailInUse($email, $target->id())) {
                throw new StaffEmailInUse;
            }

            $link = SecretTokens::issue();
            $this->tokens->putEmailChange($target->id(), $email, $link['hash'], CarbonImmutable::now()->addHours($this->settings->emailChangeHours()), $author->staffId);
            $this->platform->recordAudit(new AuditEntryDto('access.staff_user.email_change_requested', 'access.staff_user', $target->id(), null, AuditChanges::none()->personal('email')));

            // Sent to the new address: it becomes the email only once its owner uses the link.
            $this->db->afterCommit(fn () => $this->messages->staffEmailChange(StaffMapper::toDto($target), $email->value, $this->links->emailChange($link['token'])));
        });
    }
}

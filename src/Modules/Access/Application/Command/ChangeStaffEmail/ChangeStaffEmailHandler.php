<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeStaffEmail;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\Invitations;
use Modules\Access\Application\Staff\StaffLinks;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\StaffStatus;
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
        private CustomerRepository $customers,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private StaffTokenRepository $tokens,
        private StaffSecuritySettings $settings,
        private SecurityMessages $messages,
        private StaffLinks $links,
        private Invitations $invitations,
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

            // A Super Admin changes their own; anyone else's needs an admin who manages them and
            // holds every action of their role — a new email could hand the account to the admin.
            if (! ($target->isSuperAdmin() && $author->staffId === $target->id())) {
                $targetGrants = $this->grants->forStaff($target->id());
                $this->rules->requireManageable($author, $target, $targetGrants);
                $this->rules->requireCoversActionsOf($author, $targetGrants);
            }

            if ($target->email()->sameAs($email) && $target->email()->value === $email->value) {
                throw new InvalidAccessAttribute('email', 'the same as the current one');
            }

            // One email, one account (amendment 13): a customer's address is taken too, and is
            // answered the same way, so the panel never says which kind of account holds it.
            if ($this->staff->emailInUse($email, $target->id()) || $this->customers->emailInUse($email)) {
                throw new StaffEmailInUse;
            }

            // Only a working account, or one still invited: never a disabled or cancelled one.
            if ($target->status() !== StaffStatus::Active && $target->status() !== StaffStatus::Invited) {
                throw new InvalidStaffStatus($target->status());
            }

            if ($target->status() === StaffStatus::Invited) {
                $this->replaceInvitation($target, $email, $author->staffId);

                return;
            }

            $link = SecretTokens::issue();
            $this->tokens->putEmailChange($target->id(), $email, $link['hash'], CarbonImmutable::now()->addHours($this->settings->emailChangeHours()), $author->staffId);
            $this->platform->recordAudit(new AuditEntryDto('access.staff_user.email_change_requested', 'access.staff_user', $target->id(), null, AuditChanges::none()->personal('email')));

            // Sent to the new address: it becomes the email only once its owner uses the link.
            $this->db->afterCommit(fn () => $this->messages->staffEmailChange(StaffMapper::toDto($target), $email->value, $this->links->emailChange($link['token'])));
        }, 3);
    }

    /**
     * Someone who never accepted has no proven address yet: the invitation itself proves the new
     * one. The email changes at once and a new invitation goes there; the link sent to the old
     * address — perhaps a stranger's, if it was mistyped — dies (amendment 25).
     */
    private function replaceInvitation(StaffUser $target, EmailAddress $email, ?string $authorId): void
    {
        $before = clone $target;
        $target->changeEmail($email);
        $this->staff->update($target);
        $this->invitations->send($target, $authorId);
        $this->platform->recordAudit(StaffAudit::updated('access.staff_user.email_changed', $before, $target, $target->pullChanges()));
    }
}

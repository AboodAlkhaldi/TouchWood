<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitation;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitationHandler;
use Modules\Access\Application\Command\ConfirmStaffEmailChange\ConfirmStaffEmailChange;
use Modules\Access\Application\Command\ConfirmStaffEmailChange\ConfirmStaffEmailChangeHandler;
use Modules\Access\Application\Command\ConfirmStaffInvitation\ConfirmStaffInvitation;
use Modules\Access\Application\Command\ConfirmStaffInvitation\ConfirmStaffInvitationHandler;
use Modules\Access\Application\Command\SignOutStaff\SignOutStaff;
use Modules\Access\Application\Command\SignOutStaff\SignOutStaffHandler;
use Modules\Access\Presentation\Http\FormErrors;
use Modules\Access\Presentation\Http\Request\StaffCodeRequest;
use Modules\Access\Presentation\Http\Request\StaffInvitationRequest;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Domain\Error\DomainError;

/**
 * The links in staff emails. Opened in a browser signed in to the admin panel, the link signs that
 * session out first, then continues (amendment 31): the link itself is the proof.
 */
final readonly class StaffLinkController
{
    public function __construct(
        private ActorContext $actors,
        private SignOutStaffHandler $signOut,
    ) {}

    public function acceptInvitation(StaffInvitationRequest $request, string $token, AcceptStaffInvitationHandler $handler): RedirectResponse
    {
        $this->signOutFirst();

        try {
            $handler->handle(new AcceptStaffInvitation($token, $request->text('password'), $request->text('phone')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['phone']);
        }

        return redirect("/admin/invitation/{$token}/code")->with('status', __('access::auth.code_sent'));
    }

    /**
     * The password and the phone code both given: they go straight in.
     */
    public function confirmInvitation(StaffCodeRequest $request, string $token, ConfirmStaffInvitationHandler $handler): RedirectResponse
    {
        $this->signOutFirst();

        try {
            $handler->handle(new ConfirmStaffInvitation($token, $request->text('code')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect('/admin');
    }

    public function confirmEmailChange(Request $request, string $token, ConfirmStaffEmailChangeHandler $handler): RedirectResponse
    {
        $this->signOutFirst();

        try {
            $handler->handle(new ConfirmStaffEmailChange($token));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect('/admin/sign-in')->with('status', __('access::auth.email_changed'));
    }

    private function signOutFirst(): void
    {
        if ($this->actors->current()->type === ActorType::Staff) {
            $this->signOut->handle(new SignOutStaff);
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Access\Application\Command\ChangeOwnStaffPassword\ChangeOwnStaffPassword;
use Modules\Access\Application\Command\ChangeOwnStaffPassword\ChangeOwnStaffPasswordHandler;
use Modules\Access\Application\Command\RequestStaffPasswordReset\RequestStaffPasswordReset;
use Modules\Access\Application\Command\RequestStaffPasswordReset\RequestStaffPasswordResetHandler;
use Modules\Access\Application\Command\ResetStaffPassword\ResetStaffPassword;
use Modules\Access\Application\Command\ResetStaffPassword\ResetStaffPasswordHandler;
use Modules\Access\Application\Command\SignOutStaff\SignOutStaff;
use Modules\Access\Application\Command\SignOutStaff\SignOutStaffHandler;
use Modules\Access\Presentation\Http\Request\EmailRequest;
use Modules\Access\Presentation\Http\Request\NewPasswordRequest;
use Modules\Access\Presentation\Http\Request\OwnPasswordRequest;
use Shared\Domain\Error\DomainError;

/**
 * Signing out, a forgotten password, and changing one's own (spec §1.8).
 */
final readonly class StaffAccountController
{
    public function signOut(Request $request, SignOutStaffHandler $handler): RedirectResponse
    {
        $handler->handle(new SignOutStaff);

        return redirect('/admin/sign-in')->with('status', __('access::auth.signed_out'));
    }

    /**
     * Always the same answer, so it tells nobody whether the email has an account.
     */
    public function forgotPassword(EmailRequest $request, RequestStaffPasswordResetHandler $handler): RedirectResponse
    {
        $handler->handle(new RequestStaffPasswordReset($request->text('email')));

        return redirect()->back()->with('status', __('access::auth.reset_link_sent'));
    }

    public function resetPassword(NewPasswordRequest $request, string $token, ResetStaffPasswordHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new ResetStaffPassword($token, $request->text('password')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect('/admin/sign-in')->with('status', __('access::auth.password_reset'));
    }

    public function changePassword(OwnPasswordRequest $request, ChangeOwnStaffPasswordHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new ChangeOwnStaffPassword($request->text('current_password'), $request->text('password'), (string) $request->ip()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->back()->with('status', __('access::auth.password_changed'));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Access\Application\Command\ChangeOwnCustomerPassword\ChangeOwnCustomerPassword;
use Modules\Access\Application\Command\ChangeOwnCustomerPassword\ChangeOwnCustomerPasswordHandler;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomer;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomerHandler;
use Modules\Access\Application\Command\RequestCustomerPasswordReset\RequestCustomerPasswordReset;
use Modules\Access\Application\Command\RequestCustomerPasswordReset\RequestCustomerPasswordResetHandler;
use Modules\Access\Application\Command\ResendCustomerEmailVerification\ResendCustomerEmailVerification;
use Modules\Access\Application\Command\ResendCustomerEmailVerification\ResendCustomerEmailVerificationHandler;
use Modules\Access\Application\Command\ResetCustomerPassword\ResetCustomerPassword;
use Modules\Access\Application\Command\ResetCustomerPassword\ResetCustomerPasswordHandler;
use Modules\Access\Application\Command\SignInCustomer\SignInCustomer;
use Modules\Access\Application\Command\SignInCustomer\SignInCustomerHandler;
use Modules\Access\Application\Command\SignOutCustomer\SignOutCustomer;
use Modules\Access\Application\Command\SignOutCustomer\SignOutCustomerHandler;
use Modules\Access\Presentation\Http\Request\CustomerRegistrationRequest;
use Modules\Access\Presentation\Http\Request\EmailRequest;
use Modules\Access\Presentation\Http\Request\NewPasswordRequest;
use Modules\Access\Presentation\Http\Request\OwnPasswordRequest;
use Modules\Access\Presentation\Http\Request\SignInRequest;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Domain\Error\DomainError;

/**
 * Registering and signing in on the storefront (spec §1.2, §1.8, amendment 12): posts answered with
 * redirects. The pages they come from are built in the frontend foundation stage.
 *
 * These pages belong to someone not signed in. A customer who is signed in and opens their reset
 * link is signed out first, then the reset continues — the rule staff links already follow
 * (amendment 31, and the owner for customers, 2026-09-20); to change a password they remember, they
 * use their account settings instead. Registering or signing in again simply lands them in the store.
 */
final readonly class CustomerSessionController
{
    public function __construct(
        private ActorContext $actors,
        private SignOutCustomerHandler $signOut,
    ) {}

    public function register(CustomerRegistrationRequest $request, RegisterCustomerHandler $handler): RedirectResponse
    {
        if ($this->signedIn()) {
            return redirect()->route('storefront.home');
        }

        try {
            $handler->handle(new RegisterCustomer(
                $request->text('email'),
                $request->text('password'),
                $request->text('first_name'),
                $request->text('last_name'),
                $request->text('account_type'),
                $request->text('locale'),
                $request->boolean('terms'),
                (string) $request->ip(),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['email']);
        }

        // Signed in already (owner, 2026-09-20): they land in the store, with the link on its way.
        return redirect()->route('storefront.home')->with('status', __('access::auth.registered'));
    }

    public function signIn(SignInRequest $request, SignInCustomerHandler $handler): RedirectResponse
    {
        if ($this->signedIn()) {
            return redirect()->route('storefront.home');
        }

        try {
            $handler->handle(new SignInCustomer(
                $request->text('email'),
                $request->text('password'),
                (string) $request->ip(),
                $request->boolean('remember'),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['email']);
        }

        return redirect()->route('storefront.home');
    }

    public function signOut(Request $request, SignOutCustomerHandler $handler): RedirectResponse
    {
        $handler->handle(new SignOutCustomer);

        return redirect()->route('storefront.home')->with('status', __('access::auth.signed_out'));
    }

    public function forgotPassword(EmailRequest $request, RequestCustomerPasswordResetHandler $handler): RedirectResponse
    {
        $this->signOutFirst();

        try {
            $handler->handle(new RequestCustomerPasswordReset($request->text('email'), (string) $request->ip()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['email']);
        }

        // The same answer whether or not the address has an account (spec §1.8).
        return redirect()->back()->with('status', __('access::auth.reset_link_sent'));
    }

    public function resetPassword(NewPasswordRequest $request, string $token, ResetCustomerPasswordHandler $handler): RedirectResponse
    {
        $this->signOutFirst();

        try {
            $handler->handle(new ResetCustomerPassword($token, $request->text('password')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->route('storefront.home')->with('status', __('access::auth.password_reset'));
    }

    public function changePassword(OwnPasswordRequest $request, ChangeOwnCustomerPasswordHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new ChangeOwnCustomerPassword(
                $request->text('current_password'),
                $request->text('password'),
                (string) $request->ip(),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->back()->with('status', __('access::auth.password_changed'));
    }

    public function resendVerification(Request $request, ResendCustomerEmailVerificationHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new ResendCustomerEmailVerification);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->back()->with('status', __('access::auth.verification_sent'));
    }

    private function signedIn(): bool
    {
        return $this->actors->current()->type === ActorType::Customer;
    }

    /**
     * The link proves who holds it, and these pages are for someone not signed in: the session in
     * this browser ends before the reset goes on.
     */
    private function signOutFirst(): void
    {
        if ($this->signedIn()) {
            $this->signOut->handle(new SignOutCustomer);
        }
    }
}

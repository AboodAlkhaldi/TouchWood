<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Access\Application\Command\ResendStaffSignInCode\ResendStaffSignInCode;
use Modules\Access\Application\Command\ResendStaffSignInCode\ResendStaffSignInCodeHandler;
use Modules\Access\Application\Command\SendStaffSignInCodeToNewPhone\SendStaffSignInCodeToNewPhone;
use Modules\Access\Application\Command\SendStaffSignInCodeToNewPhone\SendStaffSignInCodeToNewPhoneHandler;
use Modules\Access\Application\Command\SignInStaff\SignInResult;
use Modules\Access\Application\Command\SignInStaff\SignInStaff;
use Modules\Access\Application\Command\SignInStaff\SignInStaffHandler;
use Modules\Access\Application\Command\VerifyStaffSignInAppCode\VerifyStaffSignInAppCode;
use Modules\Access\Application\Command\VerifyStaffSignInAppCode\VerifyStaffSignInAppCodeHandler;
use Modules\Access\Application\Command\VerifyStaffSignInCode\VerifyStaffSignInCode;
use Modules\Access\Application\Command\VerifyStaffSignInCode\VerifyStaffSignInCodeHandler;
use Modules\Access\Domain\Exception\AppCodeTriesUsedUp;
use Modules\Access\Presentation\Http\Request\AppCodeRequest;
use Modules\Access\Presentation\Http\Request\SignInRequest;
use Modules\Access\Presentation\Http\Request\StaffCodeRequest;
use Modules\Access\Presentation\Http\Request\StaffPhoneRequest;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Domain\Error\DomainError;

/**
 * Signing in to the admin panel (spec §1.8, §4.4), answering with redirects (amendment 12): the
 * password, a new number for a Super Admin whose phone was reset, then the SMS code - or the code from
 * the authenticator app for whom it is the method (amendment 91).
 */
final readonly class StaffSignInController
{
    public function __construct(
        private ActorContext $actors,
        private Config $config,
    ) {}

    public function password(SignInRequest $request, SignInStaffHandler $handler): RedirectResponse
    {
        if ($this->actors->current()->type === ActorType::Staff) {
            return redirect('/admin');
        }

        $trust = $request->cookie($this->trustCookie());

        try {
            $result = $handler->handle(new SignInStaff($request->text('email'), $request->text('password'), (string) $request->ip(), is_string($trust) ? $trust : null));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['email']);
        }

        return match ($result) {
            SignInResult::SignedIn => redirect('/admin'),
            SignInResult::CodeSent => redirect('/admin/sign-in/code')->with('status', __('access::auth.code_sent')),
            SignInResult::PhoneNeeded => redirect('/admin/sign-in/phone'),
            SignInResult::AppCodeNeeded => redirect('/admin/sign-in/app'),
        };
    }

    public function phone(StaffPhoneRequest $request, SendStaffSignInCodeToNewPhoneHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new SendStaffSignInCodeToNewPhone($request->text('phone')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['phone']);
        }

        return redirect('/admin/sign-in/code')->with('status', __('access::auth.code_sent'));
    }

    public function code(StaffCodeRequest $request, VerifyStaffSignInCodeHandler $handler): RedirectResponse
    {
        try {
            $trust = $handler->handle(new VerifyStaffSignInCode($request->text('code'), $request->boolean('trust_browser')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return $this->signedIn($trust);
    }

    /**
     * Into the panel - or, when the app is their method and none is set up, to its setup (amendment
     * 91), which RequireStaff sends them to from there.
     *
     * @param  array{token: string, hours: int}|null  $trust
     */
    private function signedIn(?array $trust): RedirectResponse
    {
        $response = redirect('/admin');

        if ($trust !== null) {
            // Encrypted by the "web" group, HTTP only, sent to /admin alone.
            $response->withCookie(cookie($this->trustCookie(), $trust['token'], $trust['hours'] * 60, '/admin', null, null, true, false, 'lax'));
        }

        return $response;
    }

    /** The code from the authenticator app, or a recovery code (amendment 91). */
    public function appCode(AppCodeRequest $request, VerifyStaffSignInAppCodeHandler $handler): RedirectResponse
    {
        try {
            $trust = $handler->handle(new VerifyStaffSignInAppCode($request->text('code'), $request->boolean('recovery'), $request->boolean('trust_browser')));
        } catch (AppCodeTriesUsedUp $error) {
            // The sign-in ended: back to the password, saying why.
            return redirect('/admin/sign-in')->withErrors(['form' => FormErrors::message($error)]);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return $this->signedIn($trust);
    }

    public function resend(Request $request, ResendStaffSignInCodeHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new ResendStaffSignInCode);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->back()->with('status', __('access::auth.code_sent'));
    }

    /**
     * @return non-empty-string
     */
    private function trustCookie(): string
    {
        $name = $this->config->get('access.admin.trust_cookie');

        return is_string($name) && $name !== '' ? $name : throw new LogicException('config access.admin.trust_cookie must name the trust cookie.');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Access\Application\Command\ChooseSignInMethod\ChooseSignInMethod;
use Modules\Access\Application\Command\ChooseSignInMethod\ChooseSignInMethodHandler;
use Modules\Access\Application\Command\ConfirmAuthenticatorSetup\ConfirmAuthenticatorSetup;
use Modules\Access\Application\Command\ConfirmAuthenticatorSetup\ConfirmAuthenticatorSetupHandler;
use Modules\Access\Application\Command\RemoveOwnAuthenticator\RemoveOwnAuthenticator;
use Modules\Access\Application\Command\RemoveOwnAuthenticator\RemoveOwnAuthenticatorHandler;
use Modules\Access\Application\Command\RenewRecoveryCodes\RenewRecoveryCodes;
use Modules\Access\Application\Command\RenewRecoveryCodes\RenewRecoveryCodesHandler;
use Modules\Access\Application\Command\StartAuthenticatorSetup\StartAuthenticatorSetup;
use Modules\Access\Application\Command\StartAuthenticatorSetup\StartAuthenticatorSetupHandler;
use Modules\Access\Application\Query\MyAuthenticator\MyAuthenticatorForStaff;
use Modules\Access\Presentation\Http\Middleware\RequireStaff;
use Modules\Access\Presentation\Http\Request\CurrentPasswordRequest;
use Modules\Access\Presentation\Http\Request\SignInMethodRequest;
use Modules\Access\Presentation\Http\Request\StaffCodeRequest;
use Modules\Access\Presentation\Http\Resource\AuthenticatorSetupPage;
use Modules\Access\Presentation\Http\Resource\RecoveryCodesPage;
use Shared\Domain\Error\DomainError;

/**
 * A staff member's own authenticator app (amendment 91): the setup page - the password, then the QR
 * code, then one code from the app - the recovery codes shown once, and the Security tab's choices.
 * No route here carries an id: each handler reads who is asking from the session.
 */
final readonly class StaffAuthenticatorController
{
    /**
     * The words the screens read: the setup's own (in the account file), the sign-in file for the
     * shared code words, the refusals, and the layouts'.
     *
     * @var list<string>
     */
    private const array WORDS = ['access::account', 'access::auth', 'access::errors', 'admin'];

    /** The codes travel to the page that shows them in the session's flash, for one request only. */
    private const string CODES = 'access.recovery_codes';

    private const string CODES_NEXT = 'access.recovery_codes_next';

    private const string SECURITY_TAB = '/admin/account?tab=security';

    public function __construct(
        private Page $page,
        private MyAuthenticatorForStaff $authenticator,
    ) {}

    public function setup(): Response
    {
        $setup = $this->authenticator->setupForCurrentStaff();

        // The QR code holds the secret: the browser keeps this page in its history only encrypted (review
        // of amendment 91), and the key to that is dropped when the history is cleared.
        return $this->encrypted(fn (): Response => $this->page->render('Access/Admin/AuthenticatorSetup', (new AuthenticatorSetupPage(
            $setup->hasApp,
            $setup->required,
            $setup->qrSvg,
            $setup->manualKey,
        ))->toArray(), self::WORDS));
    }

    /** The password, which opens the QR code in this browser (decision 6). */
    public function start(CurrentPasswordRequest $request, StartAuthenticatorSetupHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new StartAuthenticatorSetup($request->text('current_password'), (string) $request->ip()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect(RequireStaff::SETUP_PATH);
    }

    /** The app's first code: the app is set up, and its recovery codes are shown once. */
    public function confirm(StaffCodeRequest $request, ConfirmAuthenticatorSetupHandler $handler): RedirectResponse
    {
        try {
            $codes = $handler->handle(new ConfirmAuthenticatorSetup($request->text('code')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        // The setup's pages, QR code and all, leave the browser's history.
        Inertia::clearHistory();

        return $this->showCodes($request, $codes, '/admin', 'access::account.authenticator_set_up');
    }

    /**
     * The codes just made, once: a page opened again, or opened without them, goes on to where the
     * person was going.
     */
    public function recoveryCodes(Request $request): Response|RedirectResponse
    {
        $codes = $request->session()->get(self::CODES);
        $next = $request->session()->get(self::CODES_NEXT);
        $next = is_string($next) ? $next : self::SECURITY_TAB;

        $shown = [];

        foreach (is_array($codes) ? $codes : [] as $code) {
            if (is_string($code)) {
                $shown[] = $code;
            }
        }

        if ($shown === []) {
            return redirect($next);
        }

        // The codes sign in: kept in the browser's history only encrypted.
        return $this->encrypted(fn (): Response => $this->page->render('Access/Admin/RecoveryCodes', (new RecoveryCodesPage($shown, $next))->toArray(), self::WORDS));
    }

    public function renewRecoveryCodes(CurrentPasswordRequest $request, RenewRecoveryCodesHandler $handler): RedirectResponse
    {
        try {
            $codes = $handler->handle(new RenewRecoveryCodes($request->text('current_password'), (string) $request->ip()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return $this->showCodes($request, $codes, self::SECURITY_TAB, 'access::account.recovery_codes_renewed');
    }

    public function remove(CurrentPasswordRequest $request, RemoveOwnAuthenticatorHandler $handler): RedirectResponse
    {
        try {
            $mustSetUp = $handler->handle(new RemoveOwnAuthenticator($request->text('current_password'), (string) $request->ip()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        // The app was still the only method: a new one is set up before anything else.
        return redirect($mustSetUp ? RequireStaff::SETUP_PATH : self::SECURITY_TAB)->with('status', __('access::account.authenticator_removed'));
    }

    public function chooseMethod(SignInMethodRequest $request, ChooseSignInMethodHandler $handler): RedirectResponse
    {
        try {
            $mustSetUp = $handler->handle(new ChooseSignInMethod($request->text('method'), $request->text('current_password'), (string) $request->ip()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return $mustSetUp
            ? redirect(RequireStaff::SETUP_PATH)
            : redirect(self::SECURITY_TAB)->with('status', __('access::account.sign_in_method_saved'));
    }

    /**
     * This page alone kept encrypted in the browser's history: Inertia's switch is the response factory's,
     * which lives as long as the process does, so it is put back once the page is made.
     *
     * @param  callable(): Response  $render
     */
    private function encrypted(callable $render): Response
    {
        Inertia::encryptHistory();

        try {
            return $render();
        } finally {
            Inertia::encryptHistory(false);
        }
    }

    /**
     * @param  list<string>  $codes
     */
    private function showCodes(Request $request, array $codes, string $next, string $status): RedirectResponse
    {
        $request->session()->flash(self::CODES, $codes);
        $request->session()->flash(self::CODES_NEXT, $next);

        return redirect()->route('access.staff.authenticator.recovery-codes.page')->with('status', __($status));
    }
}

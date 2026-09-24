<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Modules\Access\Application\Query\CustomerReader;
use Modules\Access\Application\Session\CustomerSessions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Presentation\Http\Resource\CustomerRegisterPage;
use Modules\Access\Presentation\Http\Resource\CustomerSignInPage;
use Modules\Access\Presentation\Http\Resource\ResetPasswordPage;
use Modules\Access\Presentation\Http\Resource\VerifyEmailPage;

/**
 * The shop's own sign-in screens (frontend.md §3.6, F3-F6). The posts behind them were built in
 * Access step 4a; these are the pages they come from, and the panel's are {@see StaffAuthPageController}.
 *
 * Nothing here changes anything. The guards are only about where a person is: somebody already
 * signed in has no business on the register or sign-in page, and somebody signed out has none on
 * the page that offers to send their confirmation link again - it would have nothing to send it to.
 *
 * The customer's rules are their own: at least eight characters by default rather than the staff
 * number, and "keep me signed in", which staff do not have (access.md §1.8).
 */
final readonly class CustomerAuthPageController
{
    /**
     * Every screen names the files it reads - its own, plus the shop frame's.
     *
     * The frame's are {@see StorefrontArea::WORDS} - the header's country switch, its theme toggle
     * and the name or the way in. `access::errors` is what a refusal says, which arrives as a form
     * error rather than as page data.
     *
     * @var list<string>
     */
    private const array WORDS = [...StorefrontArea::WORDS, 'access::errors'];

    public function __construct(
        private Page $page,
        private CustomerSessions $sessions,
        private CustomerSecuritySettings $settings,
        private CustomerReader $customers,
    ) {}

    /** F3. */
    public function register(): Response|RedirectResponse
    {
        return $this->signedIn() ?? $this->page->render(
            'Access/Storefront/Register',
            (new CustomerRegisterPage($this->settings->passwordMinLength()))->toArray(),
            self::WORDS,
        );
    }

    /** F5. */
    public function signIn(): Response|RedirectResponse
    {
        return $this->signedIn() ?? $this->page->render(
            'Access/Storefront/SignIn',
            (new CustomerSignInPage($this->settings->rememberDays()))->toArray(),
            self::WORDS,
        );
    }

    /**
     * F4. Their own address and nothing else: the page's whole job is to say where the link went
     * and to offer another.
     *
     * A confirmed address has nothing to do here, and a visitor has no address to show - both are
     * sent where the question makes sense rather than shown an empty page.
     */
    public function verifyEmail(): Response|RedirectResponse
    {
        $customerId = $this->sessions->signedIn();

        if ($customerId === null) {
            return redirect()->route('storefront.sign-in');
        }

        $customer = $this->customers->customer($customerId);

        if ($customer === null || ($customer['email_verified'] ?? false) === true) {
            return redirect()->route('storefront.home');
        }

        return $this->page->render('Access/Storefront/VerifyEmail', (new VerifyEmailPage(
            (string) ($customer['email'] ?? ''),
            $this->settings->emailVerificationHours(),
        ))->toArray(), self::WORDS);
    }

    /** F6, the first half. */
    public function forgotPassword(): Response|RedirectResponse
    {
        return $this->signedIn() ?? $this->page->render('Access/Storefront/ForgotPassword', [], self::WORDS);
    }

    /**
     * F6, the second half. The token is not checked here: a link that has expired is refused when
     * the form is sent, with the same words for every reason, so the page cannot be used to learn
     * which tokens exist.
     *
     * No guard either - a customer who is signed in and opens their own reset link is signed out by
     * the post and the reset goes on (access.md amendment 31, and the owner for customers).
     */
    public function resetPassword(string $token): Response
    {
        return $this->page->render(
            'Access/Storefront/ResetPassword',
            (new ResetPasswordPage($token, $this->settings->passwordMinLength()))->toArray(),
            self::WORDS,
        );
    }

    /** The store they are already in, for a page that is only for somebody who is not signed in. */
    private function signedIn(): ?RedirectResponse
    {
        return $this->sessions->signedIn() === null ? null : redirect()->route('storefront.home');
    }
}

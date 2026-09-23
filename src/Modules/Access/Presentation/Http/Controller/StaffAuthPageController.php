<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Modules\Access\Application\Query\LinkPages\StaffLinkPages;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Presentation\Http\Resource\EmailChangePage;
use Modules\Access\Presentation\Http\Resource\InvitationPage;
use Modules\Access\Presentation\Http\Resource\ResetPasswordPage;
use Modules\Access\Presentation\Http\Resource\SignInCodePage;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * The sign-in screens themselves (frontend.md §3.1, A1–A8). The posts behind them were built in
 * Access step 3b; these are the pages they come from.
 *
 * Nothing here changes anything. Opening a link never acts: A6, A7 and A8 only act when the person
 * presses the button on the page (§3.1). And A2, A3 and A7 open **only in their place in the
 * flow** — reached any other way, the person is sent back to where the flow really is, because a
 * code screen with no pending sign-in behind it can do nothing but confuse.
 *
 * Every screen names the translation files it reads. `access::auth` holds the words; `access::errors`
 * holds what a refusal says, which arrives as a form error rather than as page data.
 */
final readonly class StaffAuthPageController
{
    /**
     * Every screen names the files it reads - its own, plus the shell's.
     *
     * `admin` is the shell's: the sign-in layout carries the theme toggle, and a page that ships its
     * own words but not its layout's renders the layout's keys raw, on the screen, where a person
     * reads "admin.theme.dark" instead of a word (found by running it, 2026-09-22).
     *
     * @var list<string>
     */
    private const array WORDS = ['access::auth', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private StaffSessions $sessions,
        private StaffSecuritySettings $settings,
        private StaffLinkPages $links,
        private ActorContext $actors,
    ) {}

    /** A1. Somebody already signed in has no business here. */
    public function signIn(): Response|RedirectResponse
    {
        return $this->signedIn() ?? $this->page->render('Access/Admin/SignIn', [], self::WORDS);
    }

    /** A2 — only for the pending sign-in that asked for a number. */
    public function phone(): Response|RedirectResponse
    {
        $pending = $this->sessions->pendingSignIn();

        if ($pending === null || ! $pending->needsPhone) {
            return redirect('/admin/sign-in');
        }

        return $this->page->render('Access/Admin/SignInPhone', [], self::WORDS);
    }

    /** A3 — only after the right password, and only once a code has actually gone out. */
    public function code(): Response|RedirectResponse
    {
        $pending = $this->sessions->pendingSignIn();

        if ($pending === null || $pending->needsPhone) {
            return redirect('/admin/sign-in');
        }

        return $this->page->render('Access/Admin/SignInCode', $this->codePage(
            // Masked by Access before it ever reaches a page (stage 2b, P4).
            $pending->maskedPhone,
            '/admin/sign-in/code',
            '/admin/sign-in/code/resend',
        )->toArray(), self::WORDS);
    }

    /** A4. */
    public function forgotPassword(): Response|RedirectResponse
    {
        return $this->signedIn() ?? $this->page->render('Access/Admin/ForgotPassword', [], self::WORDS);
    }

    /**
     * A5. The token is not checked here: a link that has expired is refused when the form is sent,
     * with the same words for every reason, so the page cannot be used to learn which tokens exist.
     */
    public function resetPassword(string $token): Response
    {
        return $this->page->render(
            'Access/Admin/ResetPassword',
            (new ResetPasswordPage($token, $this->settings->passwordMinLength()))->toArray(),
            self::WORDS,
        );
    }

    /**
     * A6. This one does read the invitation, because the page shows the person their own name and
     * address — an invitation that is not open sends them to A1 rather than showing an empty form.
     */
    public function acceptInvitation(string $token): Response|RedirectResponse
    {
        $invited = $this->links->invitation($token);

        if ($invited === null) {
            return redirect('/admin/sign-in');
        }

        return $this->page->render('Access/Admin/AcceptInvitation', (new InvitationPage(
            $token,
            $invited->name,
            $invited->email,
            $invited->phone,
            $this->settings->passwordMinLength(),
        ))->toArray(), self::WORDS);
    }

    /** A7 — the same code screen, after an invitation rather than after a password. */
    public function invitationCode(string $token): Response|RedirectResponse
    {
        $invited = $this->links->invitation($token);

        if ($invited === null) {
            return redirect('/admin/sign-in');
        }

        return $this->page->render('Access/Admin/SignInCode', $this->codePage(
            $invited->maskedPhone,
            "/admin/invitation/{$token}/code",
            // An invitation has no separate resend: asking again sends the same form.
            "/admin/invitation/{$token}/code",
        )->toArray(), self::WORDS);
    }

    /** A8. */
    public function confirmEmailChange(string $token): Response|RedirectResponse
    {
        $newEmail = $this->links->pendingEmailChange($token);

        if ($newEmail === null) {
            return redirect('/admin/sign-in');
        }

        return $this->page->render(
            'Access/Admin/ConfirmEmailChange',
            (new EmailChangePage($token, $newEmail))->toArray(),
            self::WORDS,
        );
    }

    /**
     * The code screen, built the same way wherever it appears: how many boxes, how long until
     * another code and how many days a browser is trusted all come from settings.
     */
    private function codePage(?string $maskedPhone, string $action, string $resendAction): SignInCodePage
    {
        return new SignInCodePage(
            $maskedPhone,
            $this->settings->codeLength(),
            $this->settings->trustedBrowserDays(),
            $this->settings->codeResendSeconds(),
            $action,
            $resendAction,
        );
    }

    private function signedIn(): ?RedirectResponse
    {
        return $this->actors->current()->type === ActorType::Staff ? redirect('/admin') : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmail;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmailHandler;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChange;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChangeHandler;
use Modules\Access\Application\Command\UpdateOwnNotificationPreferences\UpdateOwnNotificationPreferences;
use Modules\Access\Application\Command\UpdateOwnNotificationPreferences\UpdateOwnNotificationPreferencesHandler;
use Modules\Access\Application\Command\UpdateOwnStaffProfile\UpdateOwnStaffProfile;
use Modules\Access\Application\Command\UpdateOwnStaffProfile\UpdateOwnStaffProfileHandler;
use Modules\Access\Application\Command\VerifyOwnPhoneChange\VerifyOwnPhoneChange;
use Modules\Access\Application\Command\VerifyOwnPhoneChange\VerifyOwnPhoneChangeHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\MyAccount\MyAccountDto;
use Modules\Access\Application\Query\MyAccount\MyAccountForStaff;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Presentation\Http\Request\EmailRequest;
use Modules\Access\Presentation\Http\Request\NotificationSwitchRequest;
use Modules\Access\Presentation\Http\Request\OwnPhoneChangeRequest;
use Modules\Access\Presentation\Http\Request\OwnProfileRequest;
use Modules\Access\Presentation\Http\Request\StaffCodeRequest;
use Modules\Access\Presentation\Http\Resource\AccountPage;
use Modules\Access\Presentation\Http\Resource\Countries;
use Modules\Access\Presentation\Http\Resource\CountryOption;
use Modules\Access\Presentation\Http\Resource\NotificationSetting;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\PermissionScope;
use Shared\Domain\Error\DomainError;

/**
 * "Account & settings" — a staff member's own account (frontend.md §3.2, B1–B4).
 *
 * Every endpoint here is about whoever is signed in. There is no id in any of these routes and none
 * in any of these forms: the handlers read it from the session, so there is nothing to spell
 * somebody else's account with. That is also why the controller checks nothing itself — each
 * handler asserts `access.own_account.update`, which every staff member holds (handoff §19).
 *
 * Changing the password is not here. It was built in Access step 3b, it already says the thing B3
 * asks it to say, and a second endpoint doing the same work is a second endpoint to get wrong.
 */
final readonly class StaffOwnAccountController
{
    /**
     * The words the screen reads: its own, the sign-in file for the words it shares with signing
     * in, the refusals, and `admin` — the layout's. A page that ships its own words but not its
     * layout's renders the layout's keys raw, on screen, at a person.
     *
     * @var list<string>
     */
    private const array WORDS = ['access::account', 'access::auth', 'access::errors', 'admin'];

    /** @var list<string> */
    private const array TABS = ['account', 'security', 'notifications'];

    public function __construct(
        private Page $page,
        private MyAccountForStaff $account,
        private StaffSecuritySettings $settings,
    ) {}

    public function show(Request $request): Response
    {
        $account = $this->account->forCurrentStaff();
        $tab = $request->string('tab')->toString();

        return $this->page->render('Access/Admin/Account/Account', (new AccountPage(
            email: $account->email,
            pendingEmail: $account->pendingEmail,
            firstName: $account->firstName,
            lastName: $account->lastName,
            jobTitle: $account->jobTitle,
            dateOfBirth: $account->dateOfBirth,
            country: $account->country,
            address: $account->address,
            locale: $account->locale,
            avatarUrl: $account->avatarUrl,
            phone: $account->phone,
            // Only a Super Admin changes their own address; everybody else asks an admin, because
            // a new address is a way to take an account over (amendment 17).
            canChangeEmail: $account->isSuperAdmin,
            notifications: $this->notifications($account),
            countries: $this->countries(),
            passwordMinLength: $this->settings->passwordMinLength(),
            tab: in_array($tab, self::TABS, true) ? $tab : self::TABS[0],
        ))->toArray(), self::WORDS);
    }

    /** B1. */
    public function updateProfile(OwnProfileRequest $request, UpdateOwnStaffProfileHandler $handler, PlatformApi $platform): RedirectResponse
    {
        try {
            $handler->handle(new UpdateOwnStaffProfile(
                $request->text('first_name'),
                $request->text('last_name'),
                $request->text('job_title'),
                $request->text('date_of_birth'),
                $request->text('country'),
                $request->text('address') === '' ? null : $request->text('address'),
                $request->text('locale'),
                $this->avatarFor($request, $platform),
            ));
        } catch (DomainError $error) {
            return $this->refused($request, $error, ['first_name', 'last_name', 'job_title', 'date_of_birth', 'country', 'address', 'locale']);
        }

        return $this->backTo('account')->with('status', __('access::account.saved'));
    }

    /** B1, for a Super Admin: the address changes only when the link sent to it is used. */
    public function changeEmail(EmailRequest $request, ChangeStaffEmailHandler $handler): RedirectResponse
    {
        try {
            // Their own account, and no other: the id is the one signed in, never one from the form.
            $handler->handle(new ChangeStaffEmail($this->account->forCurrentStaff()->id, $request->text('email')));
        } catch (DomainError $error) {
            return $this->refused($request, $error, ['email']);
        }

        return $this->backTo('account')->with('status', __('access::account.email_change_sent'));
    }

    /** B2 — the code goes to the new number; the old one stays in use until it is entered. */
    public function requestPhoneChange(OwnPhoneChangeRequest $request, RequestOwnPhoneChangeHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RequestOwnPhoneChange(
                $request->text('phone'),
                $request->text('current_password'),
                (string) $request->ip(),
            ));
        } catch (DomainError $error) {
            // The number is kept so the dialog can show it again; the password never is.
            return $this->refused($request, $error, ['phone']);
        }

        return $this->backTo('account')->with('status', __('access::account.phone_code_sent'));
    }

    /** B2 — the code, and with it the new number. */
    public function confirmPhoneChange(StaffCodeRequest $request, VerifyOwnPhoneChangeHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new VerifyOwnPhoneChange($request->text('code')));
        } catch (DomainError $error) {
            return $this->refused($request, $error);
        }

        return $this->backTo('account')->with('status', __('access::account.phone_changed'));
    }

    /** B4 — one topic, saved the moment a switch is flipped. */
    public function updateNotifications(NotificationSwitchRequest $request, UpdateOwnNotificationPreferencesHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new UpdateOwnNotificationPreferences([
                $request->text('topic') => ['email' => $request->boolean('email'), 'panel' => $request->boolean('panel')],
            ]));
        } catch (DomainError $error) {
            return $this->refused($request, $error);
        }

        return $this->backTo('notifications')->with('status', __('access::account.saved'));
    }

    /**
     * The picture to keep: a newly uploaded one, nothing at all, or the one already there.
     *
     * The third case is read from the account rather than taken from the form. A media id sent
     * back by a browser would be accepted as a picture — and every public image in the library has
     * an id — so the form is never allowed to name one.
     */
    private function avatarFor(OwnProfileRequest $request, PlatformApi $platform): ?string
    {
        if ($request->boolean('remove_avatar')) {
            return null;
        }

        $file = $request->file('avatar');

        if (! $file instanceof UploadedFile) {
            return $this->account->forCurrentStaff()->avatarMediaId;
        }

        // Platform checks Access's own permission for this upload, not `platform.media.upload`:
        // a staff member setting their own picture should never need a media permission (P1).
        return $platform->uploadMediaFor(new ModuleUploadDto(
            'access',
            AccessPermissions::OWN_ACCOUNT_UPDATE,
            PermissionScope::global(),
            MediaVisibility::Public,
            (string) $file->getRealPath(),
            $file->getClientOriginalName(),
        ));
    }

    /**
     * Every topic, in the order the enum declares them, so the tab does not reorder itself
     * because of what somebody has switched on.
     *
     * @return list<NotificationSetting>
     */
    private function notifications(MyAccountDto $account): array
    {
        $settings = [];

        foreach (StaffNotificationTopic::cases() as $topic) {
            $toggles = $account->notifications[$topic->value] ?? ['email' => false, 'panel' => false];
            $settings[] = new NotificationSetting($topic, $toggles['email'], $toggles['panel']);
        }

        return $settings;
    }

    /**
     * @return list<CountryOption>
     */
    private function countries(): array
    {
        return Countries::in(app()->getLocale());
    }

    /**
     * A refused form, answered where the person is looking - including a refusal about permission.
     *
     * The 403 page belongs to navigation: somebody who followed a link to a screen that is not
     * theirs is not *doing* anything, and the page is the whole answer. An action taken on a screen
     * they are already reading is different - they pressed something, they are waiting, and
     * throwing the page away under them loses whatever else they had typed. That answer belongs in
     * the page (owner, 2026-09-24).
     *
     * @param  list<string>  $keep  inputs to put back in the form - never a password or a code
     */
    private function refused(Request $request, DomainError $error, array $keep = []): RedirectResponse
    {
        return FormErrors::back($request, $error, $keep);
    }

    /**
     * Back to the tab the person was on. A form that saved and returned them to the top of the
     * first tab reads as the page having forgotten what they were doing.
     */
    private function backTo(string $tab): RedirectResponse
    {
        return redirect()->route('access.staff.account.page', ['tab' => $tab]);
    }
}

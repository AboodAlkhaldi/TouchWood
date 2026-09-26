<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * "Account & settings" — everything the three tabs show (frontend.md §3.2, B1–B4).
 *
 * One page, because it is one person's account: the tabs are a way of reading it, not three
 * screens. Which tab opens travels with the data so that a form that saved and came back lands
 * where the person was, rather than at the top of the first tab.
 *
 * Nothing secret is here. The phone is masked (stage 2b, P4), there is no password of any kind,
 * and the only address shown is the one already waiting on its own confirmation link.
 */
#[TypeScript]
final class AccountPage extends Data
{
    /**
     * @param  string|null  $pendingEmail  an address waiting for its link to be used (amendment
     *                                     17); the screen says the change is pending until then
     * @param  string  $locale  the **communication** language, which is not the panel's displayed
     *                          language — the form labels them apart (§3.2, B1)
     * @param  string|null  $phone  in full, in E.164 — not masked (stage 2b step 2, 2026-09-23):
     *                              the person is looking at their own account, and they cannot
     *                              decide whether to change a number they are not shown. Null only
     *                              for an account whose phone was reset by console
     * @param  bool  $canChangeEmail  a Super Admin changes their own address (amendment 17);
     *                                anyone else is told to ask an admin
     * @param  list<NotificationSetting>  $notifications  every topic, in the order they are shown
     * @param  list<CountryOption>  $countries  every ISO country, named in the page's language
     * @param  int  $passwordMinLength  from the setting, never written into the screen
     * @param  string  $tab  which tab opens: account, security or notifications
     */
    public function __construct(
        public string $email,
        public ?string $pendingEmail,
        public string $firstName,
        public string $lastName,
        public string $jobTitle,
        public string $dateOfBirth,
        public string $country,
        public ?string $address,
        public string $locale,
        public ?string $avatarUrl,
        public ?string $phone,
        public bool $canChangeEmail,
        public array $notifications,
        public array $countries,
        public int $passwordMinLength,
        /** @var list<StaffSessionRow> where they are signed in, most recently seen first */
        public array $sessions,
        /** @var list<TrustedBrowserRow> the browsers that skip the SMS code, until they expire */
        public array $trustedBrowsers,
        public string $tab,
    ) {}
}

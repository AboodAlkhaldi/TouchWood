<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAccount;

/**
 * A staff member's own account, as their own settings screen reads it (frontend.md §3.2, B1–B4).
 *
 * Only ever about the person asking: the id comes from who is signed in, never from a request, so
 * there is no id here to hand it somebody else's.
 */
final readonly class MyAccountDto
{
    /**
     * @param  string  $dateOfBirth  YYYY-MM-DD, as the form's date input wants it
     * @param  string  $country  ISO 3166-1 alpha-2
     * @param  string  $locale  the **communication** language: emails and SMS codes come in it
     *                          (amendment 16). Not the panel's displayed language, which is this
     *                          browser's own choice
     * @param  string|null  $phone  in full, in E.164. Masking it was considered and rejected
     *                              (stage 2b step 2, 2026-09-23): this is the person's own account
     *                              page, reached only after a password and an SMS code, and the
     *                              same page already shows their name, email, date of birth and
     *                              address. Masking one field among those would be inconsistent,
     *                              and it would stop them checking which number is on file before
     *                              they change it — which is what the screen is for. P4's masking
     *                              is for the sign-in code screen, shown *before* anybody has
     *                              proved who they are
     * @param  string|null  $pendingEmail  the address a change is waiting on; null when none is
     * @param  array<string, array{email: bool, panel: bool}>  $notifications  topic => toggles
     */
    public function __construct(
        /** Their own id, which the panel already knows: the shell carries it on every page. */
        public string $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $jobTitle,
        public string $dateOfBirth,
        public string $country,
        public ?string $address,
        public string $locale,
        /**
         * The picture as it is stored. It never reaches the page — the screen is sent the URL
         * below — but the controller needs it to leave an unchanged picture alone: a media id
         * arriving back from a browser would let anybody wear any public image in the library.
         */
        public ?string $avatarMediaId,
        public ?string $avatarUrl,
        public ?string $phone,
        public bool $isSuperAdmin,
        public ?string $pendingEmail,
        public array $notifications,
    ) {}
}

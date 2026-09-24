<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAccount;

use Modules\Access\Public\Enums\AccountType;

/**
 * A customer's own account, as their own pages read it (frontend.md §3.6, F7-F10).
 *
 * The staff member's counterpart is {@see MyAccountDto}, and this is a different shape because a
 * customer is a different thing: no job title, no permissions, no notification preferences - and
 * two facts a staff member has no equivalent of, the store they registered in and whether they may
 * order yet.
 *
 * Only ever about the person asking: the id comes from who is signed in, never from a request, so
 * there is no id here to hand it somebody else's.
 */
final readonly class MyCustomerAccountDto
{
    /**
     * @param  string  $locale  the **communication** language: their email and SMS arrive in it
     *                          (amendment 16). Not the language the shop is being read in, which
     *                          is in the address
     * @param  string|null  $phone  in full, in E.164, and not masked - the same reasoning as the
     *                              staff account's, written out in {@see MyAccountDto}: this is
     *                              their own page, and they cannot decide whether to change a
     *                              number they are not allowed to read
     * @param  string  $homeStoreId  the store they registered in. It never changes, and they may
     *                               shop in any store regardless (access.md §1.1)
     * @param  string|null  $deletionScheduledFor  YYYY-MM-DD when a deletion is pending; null when
     *                                             none is. Signing in cancels it (§1.10), so a
     *                                             customer reading this page has already cancelled
     *                                             theirs - it is here because F10's own screens
     *                                             need it, and a page that lies about it is worse
     *                                             than one that omits it
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public AccountType $accountType,
        public string $locale,
        public ?string $phone,
        public bool $emailVerified,
        public bool $phoneVerified,
        /** Both verifications done: until then they may look and fill a basket, not order (§1.2). */
        public bool $mayOrder,
        public string $homeStoreId,
        public ?string $deletionScheduledFor,
    ) {}
}

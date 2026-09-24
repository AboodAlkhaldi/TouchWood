<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F7 and F8 - a customer's own account (frontend.md §3.6).
 *
 * One page with tabs, as the panel's is, because it is one person's account: it only ever shows
 * the person looking at it, and no route behind it carries an id.
 */
#[TypeScript]
final class CustomerAccountPage extends Data
{
    /**
     * @param  string  $tab  which tab the server wants open - so a form that saved comes back to
     *                       the tab the person was on rather than to the top of the first one
     * @param  string  $email  never editable, and the screen says so (§3.6)
     * @param  string  $accountType  INDIVIDUAL or COMPANY, chosen at registration and immutable
     * @param  string  $locale  the language we write to them in, which is not the language the
     *                          shop is being read in
     * @param  string|null  $phone  in full: this is their own page, and they cannot decide whether
     *                              to change a number they are not allowed to read
     * @param  string  $homeStore  the store they registered in, named in the page's language. It
     *                             never changes, and they may shop in any store regardless
     *
     * The languages to choose between are not here: the shop already carries them on every page,
     * and they are Platform's to say, not Access's.
     * @param  list<AddressBookStore>  $addresses  every country, with what they have in each (F9)
     * @param  int  $deletionDays  how long a closed account waits before it is anonymized, which
     *                             the screen says in words before anybody confirms (F10)
     */
    public function __construct(
        public string $tab,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $accountType,
        public string $locale,
        public ?string $phone,
        public bool $emailVerified,
        public bool $phoneVerified,
        /** Both done: until then they may look around and fill a basket, but not order. */
        public bool $mayOrder,
        public string $homeStore,
        public int $passwordMinimumLength,
        public array $addresses,
        public int $deletionDays,
    ) {}
}

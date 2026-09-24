<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCode;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCodeHandler;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfile;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfileHandler;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhone;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhoneHandler;
use Modules\Access\Application\Query\MyAccount\MyAccountForCustomer;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Presentation\Http\Request\CustomerCodeRequest;
use Modules\Access\Presentation\Http\Request\CustomerPhoneRequest;
use Modules\Access\Presentation\Http\Request\CustomerProfileRequest;
use Modules\Access\Presentation\Http\Resource\CustomerAccountPage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * A customer's own account (frontend.md §3.6, F7 and F8).
 *
 * One page with tabs, as the panel's account screen is, and one POST per thing it can change. **No
 * route here carries an id**: every handler reads who is asking from the session, so there is
 * nothing to spell somebody else's account with - the same rule the panel's own account follows.
 *
 * Changing the password is not here. That endpoint already exists and already says the right
 * thing; this page posts to it.
 */
final readonly class CustomerOwnAccountController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'access::account', 'access::errors'];

    /** The tabs this page has, and the one anybody arriving without asking gets. */
    private const array TABS = ['profile', 'security', 'phone'];

    public function __construct(
        private Page $page,
        private MyAccountForCustomer $accounts,
        private CustomerSecuritySettings $settings,
        private PlatformApi $platform,
        private Application $app,
    ) {}

    public function show(Request $request): Response
    {
        return $this->page->render('Access/Storefront/Account/Account', $this->props($request)->toArray(), self::WORDS);
    }

    /** F8 - their name, and the language we write to them in. The email is never editable. */
    public function updateProfile(CustomerProfileRequest $request, UpdateCustomerProfileHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new UpdateCustomerProfile(
                $request->text('first_name'),
                $request->text('last_name'),
                $request->text('locale'),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['first_name', 'last_name', 'locale']);
        }

        return $this->backTo('profile', 'access::account.saved');
    }

    /**
     * F7, the first half: a code to the number they entered.
     *
     * No password here, unlike the panel's. A staff member's number is where every sign-in code
     * goes, so moving it is a second factor moving; a customer signs in with an email and a
     * password alone, and their number is for orders and delivery (access.md §1.3, §1.8).
     */
    public function requestPhoneCode(CustomerPhoneRequest $request, RequestCustomerPhoneCodeHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RequestCustomerPhoneCode($request->text('phone')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['phone']);
        }

        return $this->backTo('phone', 'access::auth.code_sent');
    }

    /** F7, the second half. The number in use does not change until the code is right. */
    public function confirmPhone(CustomerCodeRequest $request, VerifyCustomerPhoneHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new VerifyCustomerPhone($request->text('code')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['code']);
        }

        return $this->backTo('phone', 'access::account.shop_phone_changed');
    }

    private function props(Request $request): CustomerAccountPage
    {
        $account = $this->accounts->forCurrentCustomer();
        $locale = $this->app->getLocale();
        $tab = $request->query('tab');
        $store = $this->platform->store(StoreId::fromString($account->homeStoreId));

        return new CustomerAccountPage(
            tab: is_string($tab) && in_array($tab, self::TABS, true) ? $tab : self::TABS[0],
            firstName: $account->firstName,
            lastName: $account->lastName,
            email: $account->email,
            accountType: $account->accountType->value,
            locale: $account->locale,
            phone: $account->phone,
            emailVerified: $account->emailVerified,
            phoneVerified: $account->phoneVerified,
            mayOrder: $account->mayOrder,
            // Named in the language the page is being read in, which is not necessarily the
            // language we write to them in.
            homeStore: $store?->name->in($locale) ?? '',
            passwordMinimumLength: $this->settings->passwordMinLength(),
        );
    }

    /**
     * Back to the tab they were on. A save that drops somebody at the top of the first tab reads
     * as the page having forgotten what they were doing (the panel's own lesson, §3.2).
     */
    private function backTo(string $tab, string $message): RedirectResponse
    {
        return redirect()->route('storefront.account', ['tab' => $tab])->with('status', __($message));
    }
}

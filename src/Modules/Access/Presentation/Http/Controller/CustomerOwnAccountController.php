<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\StorefrontArea;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Command\DeleteAddress\DeleteAddress;
use Modules\Access\Application\Command\DeleteAddress\DeleteAddressHandler;
use Modules\Access\Application\Command\RequestAccountDeletion\RequestAccountDeletion;
use Modules\Access\Application\Command\RequestAccountDeletion\RequestAccountDeletionHandler;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCode;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCodeHandler;
use Modules\Access\Application\Command\SaveAddress\SaveAddress;
use Modules\Access\Application\Command\SaveAddress\SaveAddressHandler;
use Modules\Access\Application\Command\SetDefaultAddress\SetDefaultAddress;
use Modules\Access\Application\Command\SetDefaultAddress\SetDefaultAddressHandler;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfile;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfileHandler;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhone;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhoneHandler;
use Modules\Access\Application\Query\MyAccount\MyAccountForCustomer;
use Modules\Access\Application\Query\MyAccount\MyAddressesForCustomer;
use Modules\Access\Application\Query\MyAccount\MyAddressesInStoreDto;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Presentation\Http\Request\CurrentPasswordRequest;
use Modules\Access\Presentation\Http\Request\CustomerAddressRequest;
use Modules\Access\Presentation\Http\Request\CustomerCodeRequest;
use Modules\Access\Presentation\Http\Request\CustomerPhoneRequest;
use Modules\Access\Presentation\Http\Request\CustomerProfileRequest;
use Modules\Access\Presentation\Http\Resource\AddressBookStore;
use Modules\Access\Presentation\Http\Resource\AddressFieldRow;
use Modules\Access\Presentation\Http\Resource\AddressRow;
use Modules\Access\Presentation\Http\Resource\CustomerAccountPage;
use Modules\Access\Public\Dto\AddressDto;
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
    private const array TABS = ['profile', 'security', 'phone', 'addresses', 'close'];

    public function __construct(
        private Page $page,
        private MyAccountForCustomer $accounts,
        private MyAddressesForCustomer $addresses,
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

    /**
     * F9 - one address saved, new or changed.
     *
     * Which fields it must carry is the store's format, not this controller's: staff change that
     * as data, and the domain checks the values against it. An address never moves country - one
     * in another store is a new address there (access.md §1.9).
     */
    public function saveAddress(CustomerAddressRequest $request, SaveAddressHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new SaveAddress(
                storeId: $request->text('store_id'),
                label: $request->text('label'),
                recipientName: $request->text('recipient_name'),
                phone: $request->text('phone'),
                fields: $request->fields(),
                // The map pin stays empty in this stage: no map provider is chosen, and a pin
                // half-filled is worse than none (frontend.md §3.6, decided 2026-09-19).
                isDefault: $request->boolean('is_default'),
                addressId: $request->text('address_id') === '' ? null : $request->text('address_id'),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['store_id', 'label', 'recipient_name', 'phone', 'fields']);
        }

        return $this->backTo('addresses', 'access::account.address_saved');
    }

    /** F9 - the one a courier is given unless the customer picks another at checkout. */
    public function setDefaultAddress(Request $request, string $address, SetDefaultAddressHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new SetDefaultAddress($address));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return $this->backTo('addresses', 'access::account.address_default_set');
    }

    /**
     * F9 - removing one. A store that has any address always has a default, so removing the
     * default moves the flag to the newest of the rest; that is the handler's to do.
     */
    public function deleteAddress(Request $request, string $address, DeleteAddressHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeleteAddress($address));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return $this->backTo('addresses', 'access::account.address_deleted');
    }

    /**
     * F10 - closing the account, confirmed with the password (access.md §1.10).
     *
     * The account is locked at once and anonymized after fourteen days, and **every session ends
     * the moment it is confirmed** (amendment 45): the domain raises the session version, so this
     * browser is a visitor again on its next request. Which is why they land on the store home
     * rather than back on a page they can no longer open - with the date, and with the one way
     * back said plainly: sign in before then and nothing is deleted.
     *
     * There is no cancel button anywhere in the account for the same reason: they cannot reach it.
     */
    public function close(CurrentPasswordRequest $request, RequestAccountDeletionHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RequestAccountDeletion($request->text('current_password'), (string) $request->ip()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['current_password']);
        }

        return redirect()->route('storefront.home')->with('status', __('access::account.closed', [
            'date' => CarbonImmutable::now()->addDays(RequestAccountDeletionHandler::DAYS)->format('Y-m-d'),
        ]));
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
            addresses: $this->addressBook($locale),
            deletionDays: RequestAccountDeletionHandler::DAYS,
        );
    }

    /**
     * Every country, with what the customer has in each (F9).
     *
     * The names are the page's language; the fields are the store's own, already in its order.
     *
     * @return list<AddressBookStore>
     */
    private function addressBook(string $locale): array
    {
        return array_map(function (MyAddressesInStoreDto $store) use ($locale): AddressBookStore {
            $named = $this->platform->store(StoreId::fromString($store->storeId));

            return new AddressBookStore(
                storeId: $store->storeId,
                storeCode: $store->storeCode,
                storeName: $named?->name->in($locale) ?? $store->storeCode,
                hasFormat: $store->hasFormat,
                fields: array_map(fn ($field): AddressFieldRow => new AddressFieldRow(
                    $field->key,
                    $locale === 'ar' ? $field->labelAr : $field->labelEn,
                    $field->required,
                    $field->maxLength,
                ), $store->fields),
                addresses: array_map(fn (AddressDto $address): AddressRow => new AddressRow(
                    $address->id,
                    $address->label,
                    $address->recipientName,
                    $address->phone,
                    $address->fields,
                    $address->formatted,
                    $address->isDefault,
                    $address->isComplete,
                ), $store->addresses),
                limit: $store->limit,
                full: count($store->addresses) >= $store->limit,
            );
        }, $this->addresses->forCurrentCustomer());
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

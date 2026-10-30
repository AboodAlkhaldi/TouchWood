<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\StorefrontArea;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
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
use Modules\Access\Application\Command\SetOwnMarketingConsent\SetOwnMarketingConsent;
use Modules\Access\Application\Command\SetOwnMarketingConsent\SetOwnMarketingConsentHandler;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfile;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfileHandler;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhone;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhoneHandler;
use Modules\Access\Application\Query\MyAccount\MyAccountForCustomer;
use Modules\Access\Application\Query\MyAccount\MyAddressesForCustomer;
use Modules\Access\Application\Query\MyAccount\MyAddressesInStoreDto;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Presentation\Http\CustomerAccountTabs;
use Modules\Access\Presentation\Http\Request\CurrentPasswordRequest;
use Modules\Access\Presentation\Http\Request\CustomerAddressRequest;
use Modules\Access\Presentation\Http\Request\CustomerCodeRequest;
use Modules\Access\Presentation\Http\Request\CustomerPhoneRequest;
use Modules\Access\Presentation\Http\Request\CustomerProfileRequest;
use Modules\Access\Presentation\Http\Request\MarketingConsentRequest;
use Modules\Access\Presentation\Http\Resource\AddressBookStore;
use Modules\Access\Presentation\Http\Resource\AddressCountryOption;
use Modules\Access\Presentation\Http\Resource\AddressFieldRow;
use Modules\Access\Presentation\Http\Resource\AddressFormData;
use Modules\Access\Presentation\Http\Resource\AddressRow;
use Modules\Access\Presentation\Http\Resource\CustomerAccountPage;
use Modules\Access\Presentation\Http\Resource\PlaceOption;
use Modules\Access\Presentation\Http\Resource\ServedByStore;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\PlaceDto;
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

    public function __construct(
        private Page $page,
        private MyAccountForCustomer $accounts,
        private MyAddressesForCustomer $addresses,
        private CustomerSecuritySettings $settings,
        private PlatformApi $platform,
        private CustomerAccountPages $accountPages,
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

    /** The "offers and news" switch (amendment 79), on the profile tab. */
    public function updateMarketingConsent(MarketingConsentRequest $request, SetOwnMarketingConsentHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new SetOwnMarketingConsent($request->boolean('marketing_consent')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['marketing_consent']);
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
     * as data, and the domain checks the values against it. An address never moves to another store;
     * its country and places are Platform's (access.md amendment 76).
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
                // The country and the places picked, each empty where typed (amendment 76(d), (e));
                // the picked places' names are filled in by Access, in the page's language.
                countryCode: $request->text('country') === '' ? null : $request->text('country'),
                regionId: $request->text('region_id') === '' ? null : $request->text('region_id'),
                cityId: $request->text('city_id') === '' ? null : $request->text('city_id'),
                districtId: $request->text('district_id') === '' ? null : $request->text('district_id'),
                language: app()->getLocale(),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['store_id', 'label', 'recipient_name', 'phone', 'fields', 'country', 'region_id', 'city_id', 'district_id', 'places', 'administrative_area', 'city', 'district']);
        }

        // Back to the page that sent them here, if it is one of their account's (amendment 51).
        $return = $this->returnPage($request->input('return'), $this->accounts->forCurrentCustomer()->accountType);

        return $return === null
            ? $this->backTo('addresses', 'access::account.address_saved')
            : redirect()->route($return->routeName)->with('status', __('access::account.address_saved'));
    }

    /** F9 - the one a courier is given unless the customer picks another at checkout. */
    public function setDefaultAddress(Request $request, string $address, SetDefaultAddressHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new SetDefaultAddress($address));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return $this->backTo('addresses', 'access::account.address_default_set', $this->returnName($request));
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

        return $this->backTo('addresses', 'access::account.address_deleted', $this->returnName($request));
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
            tab: is_string($tab) && in_array($tab, CustomerAccountTabs::ALL, true) ? $tab : CustomerAccountTabs::ALL[0],
            firstName: $account->firstName,
            lastName: $account->lastName,
            email: $account->email,
            accountType: $account->accountType->value,
            communicationLocale: $account->locale,
            phone: $account->phone,
            emailVerified: $account->emailVerified,
            phoneVerified: $account->phoneVerified,
            phoneCountryUnlisted: $account->phoneCountryUnlisted,
            mayOrder: $account->mayOrder,
            // Named in the language the page is being read in, which is not necessarily the
            // language we write to them in.
            homeStore: $store?->name->in($locale) ?? '',
            passwordMinimumLength: $this->settings->passwordMinLength(),
            addresses: $this->addressBook($locale),
            deletionDays: RequestAccountDeletionHandler::DAYS,
            returnTo: $this->returnPage($request->query('return'), $account->accountType)?->name(),
            codeLength: $this->settings->codeLength(),
            marketingConsent: $account->marketingConsent,
        );
    }

    /**
     * The page `return` names (amendment 51): one registered for this account's type, or nothing -
     * any other value is ignored, so the parameter can never send anybody off the shop.
     */
    private function returnPage(mixed $name, AccountType $type): ?CustomerAccountPageDto
    {
        return is_string($name) ? $this->accountPages->find($type, $name) : null;
    }

    /**
     * Every store, with what the customer has in each (F9).
     *
     * The names are the page's language; the fields are the store's own, already in its order. The
     * countries a form offers come only when it is opened (addressForm, the owner 2026-10-29): what
     * the book itself needs of Platform's places is read only when there are addresses to need it.
     *
     * @return list<AddressBookStore>
     */
    private function addressBook(string $locale): array
    {
        $stores = $this->addresses->forCurrentCustomer();
        $ids = [];
        $typed = false;

        foreach ($stores as $store) {
            foreach ($store->addresses as $address) {
                array_push($ids, ...array_values(array_filter([$address->regionId, $address->cityId, $address->districtId])));
                $typed = $typed || $address->regionId === null;
            }
        }

        // The places every address names, in one read, for the retired ones (76(i)); and, only for a
        // typed address, which countries have a list now (76(j)) - one query each, none for an empty book.
        $places = $ids === [] ? [] : $this->platform->place(array_values(array_unique($ids)));
        $withPlaces = [];

        if ($typed) {
            foreach ($this->platform->addressCountries($locale) as $country) {
                $withPlaces[$country->code] = $country->hasPlaces;
            }
        }

        return array_map(function (MyAddressesInStoreDto $store) use ($locale, $withPlaces, $places): AddressBookStore {
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
                    $address->countryCode,
                    $address->regionId,
                    $address->cityId,
                    $address->districtId,
                    array_values(array_filter(
                        [$address->regionId, $address->cityId, $address->districtId],
                        static fn (?string $id): bool => $id !== null && ($places[$id]->retired ?? false),
                    )),
                    $address->regionId === null && ($withPlaces[$address->countryCode] ?? false),
                ), $store->addresses),
                limit: $store->limit,
                full: count($store->addresses) >= $store->limit,
                countryCode: $named->countryCode ?? '',
            );
        }, $stores);
    }

    /**
     * What one store's address form offers, read when Add Address or Edit Address is pressed (the
     * owner, 2026-10-29): Platform's address countries, the store's own first (Q1), each with the
     * other store that ships there (76(c), Q2), and the own country's regions where it has a list,
     * so the first picker opens at once. One query for the countries, one per store for its ship-to
     * list, one for the regions. The book's store comes as `?book=`: `{store}` is already the
     * storefront's own, in the path.
     */
    public function addressForm(Request $request): JsonResponse
    {
        $locale = $this->app->getLocale();
        $other = $locale === 'ar' ? 'en' : 'ar';
        $code = $request->query('book');
        $store = is_string($code) ? $this->platform->storeByCode(strtolower($code)) : null;

        if ($store === null || ! $store->isActive) {
            abort(404);
        }

        $shipsFrom = [];

        foreach ($this->platform->allStores() as $shipping) {
            foreach ($this->platform->storeCountries($shipping->storeId()) as $country) {
                $shipsFrom[$country] = $shipping;
            }
        }

        $own = $store->countryCode;
        $ownHasPlaces = false;
        $countries = [];

        foreach ($this->platform->addressCountries($locale) as $country) {
            $by = $shipsFrom[$country->code] ?? null;
            $ownHasPlaces = $ownHasPlaces || ($country->code === $own && $country->hasPlaces);
            $countries[] = new AddressCountryOption(
                $country->code,
                $country->name->in($locale),
                $country->name->in($other),
                $country->hasPlaces,
                $by === null || $by->id === $store->id ? null : new ServedByStore($by->code, $by->name->in($locale), $by->isActive),
            );
        }

        // The store's own country first (Q1), then the rest as the language sorts them.
        usort($countries, static fn (AddressCountryOption $a, AddressCountryOption $b): int => ($b->code === $own) <=> ($a->code === $own));

        return response()->json(new AddressFormData(
            $countries,
            $ownHasPlaces ? array_map(
                static fn (PlaceDto $place): PlaceOption => new PlaceOption($place->id, $place->name->in($locale), $place->name->in($other)),
                $this->platform->regions($own, $locale),
            ) : [],
        ));
    }

    /**
     * F9's lists, read as the customer picks (frontend.md §8.4 Q7): a country's regions, a region's
     * cities or a city's districts - in use only, sorted as the page's language sorts. One query each.
     */
    public function places(Request $request): JsonResponse
    {
        $locale = $this->app->getLocale();
        $other = $locale === 'ar' ? 'en' : 'ar';
        $country = $request->query('country');
        $region = $request->query('region');
        $city = $request->query('city');

        $found = match (true) {
            is_string($country) && preg_match('/\A[A-Za-z]{2}\z/', $country) === 1 => $this->platform->regions($country, $locale),
            is_string($region) && $region !== '' => $this->platform->cities(strtolower($region), $locale),
            is_string($city) && $city !== '' => $this->platform->districts(strtolower($city), $locale),
            default => abort(404),
        };

        return response()->json(['places' => array_map(
            static fn (PlaceDto $place): PlaceOption => new PlaceOption($place->id, $place->name->in($locale), $place->name->in($other)),
            $found,
        )]);
    }

    /**
     * Back to the tab they were on. A save that drops somebody at the top of the first tab reads
     * as the page having forgotten what they were doing (the panel's own lesson, §3.2).
     */
    /**
     * @param  string|null  $return  the page the tab goes on carrying (amendment 52), already one of
     *                               this account's
     */
    private function backTo(string $tab, string $message, ?string $return = null): RedirectResponse
    {
        $query = $return === null ? ['tab' => $tab] : ['tab' => $tab, 'return' => $return];

        return redirect()->route('storefront.account', $query)->with('status', __($message));
    }

    /**
     * The `return` a change on the Addresses tab came with, kept for the tab it goes back to
     * (amendment 52) — only a page registered for this account's type.
     */
    private function returnName(Request $request): ?string
    {
        if (! is_string($request->input('return'))) {
            return null;
        }

        return $this->returnPage($request->input('return'), $this->accounts->forCurrentCustomer()->accountType)?->name();
    }
}

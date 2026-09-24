<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Access\Application\Command\RequestAccountDeletion\RequestAccountDeletionHandler;
use Modules\Access\Application\Query\CustomerActions\CustomerActionsForReader;
use Modules\Access\Application\Query\ListCustomers\CustomerSummary;
use Modules\Access\Application\Query\ListCustomers\ListCustomers;
use Modules\Access\Application\Query\ListCustomers\ListCustomersHandler;
use Modules\Access\Application\Query\ViewCustomer\ViewCustomer;
use Modules\Access\Application\Query\ViewCustomer\ViewCustomerHandler;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * Access's customer reads, in the shape the staff screens want (frontend.md §3.7).
 *
 * It decides nothing. **Who appears in the list is Access's answer** - the customers whose home
 * store is one of the reader's, and everyone for a Super Admin - and so is what may be done to
 * them. What happens here is grouping, translation, and turning a store id into a store's name.
 *
 * The counterpart for staff is {@see StaffPages}, and this is deliberately smaller: a customer is
 * never edited by staff beyond blocking and deletion, because their profile is their own.
 */
final readonly class CustomerPages
{
    public function __construct(
        private Application $app,
        private PlatformApi $platform,
        private CustomerActionsForReader $actions,
        private ListCustomersHandler $list,
        private ViewCustomerHandler $view,
    ) {}

    /** G1. */
    public function list(?string $search, ?string $status, ?string $accountType, int $page): CustomerListPage
    {
        $found = $this->list->handle(new ListCustomers($search, $status, $accountType, $page));
        $stores = $this->storeNames();

        return new CustomerListPage(
            customers: array_map(fn (CustomerSummary $customer): CustomerRow => $this->row($customer, $stores), $found->customers),
            total: $found->total,
            page: $found->page,
            perPage: $found->perPage,
            search: $search,
            status: $status,
            accountType: $accountType,
            statuses: array_map(static fn (CustomerStatus $case): string => $case->value, CustomerStatus::cases()),
            accountTypes: array_map(static fn (AccountType $case): string => $case->value, AccountType::cases()),
        );
    }

    /** G2. */
    public function view(string $customerId): CustomerDetailsPage
    {
        $details = $this->view->handle(new ViewCustomer($customerId));
        $stores = $this->storeNames();
        // What may be done is Access's answer, never this file's: a screen that worked it out
        // itself would be deciding, and there is a test that forbids exactly that.
        $may = $this->actions->forCustomer($customerId);

        /** @var array<string, list<AddressRow>> $byStore */
        $byStore = [];

        foreach ($details->addresses as $address) {
            $byStore[$address->storeId][] = $this->address($address);
        }

        return new CustomerDetailsPage(
            customer: $this->row($details->customer, $stores),
            locale: $details->locale,
            addresses: array_map(
                static fn (string $storeId): CustomerAddressGroup => new CustomerAddressGroup(
                    $storeId,
                    $stores[$storeId] ?? $storeId,
                    $byStore[$storeId] ?? [],
                ),
                array_keys($byStore),
            ),
            // Offering is never allowing: each handler asks again (handoff §19).
            mayBlock: $may->mayBlock,
            mayUnblock: $may->mayUnblock,
            mayStartDeletion: $may->mayStartDeletion,
            mayCancelDeletion: $may->mayCancelDeletion,
            deletionDays: RequestAccountDeletionHandler::DAYS,
        );
    }

    /**
     * @param  array<string, string>  $stores
     */
    private function row(CustomerSummary $customer, array $stores): CustomerRow
    {
        return new CustomerRow(
            id: $customer->id,
            name: trim($customer->firstName.' '.$customer->lastName),
            email: $customer->email,
            phone: $customer->phone,
            accountType: $customer->accountType->value,
            status: $customer->status->value,
            emailVerified: $customer->emailVerified,
            phoneVerified: $customer->phoneVerified,
            deletionScheduledFor: $customer->deletionScheduledFor,
            anonymized: $customer->anonymized,
            homeStore: $stores[$customer->homeStoreId] ?? $customer->homeStoreId,
            registeredAt: $customer->registeredAt,
        );
    }

    private function address(AddressDto $address): AddressRow
    {
        return new AddressRow(
            $address->id,
            $address->label,
            $address->recipientName,
            $address->phone,
            $address->fields,
            $address->formatted,
            $address->isDefault,
            $address->isComplete,
        );
    }

    /**
     * Every store's name in the panel's language, by id.
     *
     * @return array<string, string>
     */
    private function storeNames(): array
    {
        $locale = $this->app->getLocale();
        $names = [];

        foreach ($this->platform->stores() as $store) {
            $names[$store->storeId()->value] = $store->name->in($locale);
        }

        return $names;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Access\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Access\Application\Address\AddressMapper;
use Modules\Access\Application\Address\OpenStores;
use Modules\Access\Application\Command\WithdrawMarketingConsent\WithdrawMarketingConsent;
use Modules\Access\Application\Command\WithdrawMarketingConsent\WithdrawMarketingConsentHandler;
use Modules\Access\Application\Customer\CustomerMapper;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\ServedPhones;
use Modules\Access\Application\Staff\StaffDisplayNames;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Dto\StaffNotificationPreferenceDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

final readonly class AccessApiImpl implements AccessApi
{
    public function __construct(
        private StaffUserRepository $staff,
        private NotificationPreferenceRepository $preferences,
        private CustomerRepository $customers,
        private CustomerMapper $customerMapper,
        private AddressRepository $addresses,
        private AddressMapper $addressMapper,
        private OpenStores $openStores,
        private StaffDisplayNames $displayNames,
        private Authorizer $authorizer,
        private WithdrawMarketingConsentHandler $withdrawConsent,
        private ServedPhones $phones,
    ) {}

    public function customer(string $customerId): ?CustomerDto
    {
        $customer = $this->customers->find($customerId);

        return $customer === null ? null : $this->customerMapper->toDto($customer);
    }

    public function customerMayOrder(string $customerId): bool
    {
        $customer = $this->customers->find($customerId);

        // A phone SMS no longer reaches is as good as unverified (amendment 90), as CustomerDto says.
        return $customer !== null && $customer->mayOrder() && $this->phones->reaches($customer->phone());
    }

    public function customers(array $customerIds): array
    {
        if (count($customerIds) > 1000) {
            throw new InvalidArgumentException('At most 1000 customers a read, not '.count($customerIds).'.');
        }

        $found = [];

        foreach ($this->customers->findMany($customerIds) as $customer) {
            $dto = $this->customerMapper->toDto($customer);
            $found[$dto->id] = $dto;
        }

        return $found;
    }

    public function customerIdsInStore(StoreId $store, AccountType $accountType, ?string $after, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException("A page of customers is 1 to 1000, not {$limit}.");
        }

        return $this->customers->idsRegisteredIn($store->value, $accountType, $after, $limit);
    }

    public function customerIdsMatching(string $text, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException("A search answers 1 to 1000 customers, not {$limit}.");
        }

        $text = trim($text);
        $length = mb_strlen($text);

        if ($length < 3 || $length > 100) {
            throw new InvalidAccessAttribute('search', '3 to 100 characters');
        }

        // A control character or a byte that is not UTF-8 never reaches PostgreSQL as a pattern.
        if (! mb_check_encoding($text, 'UTF-8') || preg_match('/\p{Cc}/u', $text) === 1) {
            throw new InvalidAccessAttribute('search', 'plain text');
        }

        // Whose emails and phones this reader may match: the customers they may view (amendment
        // 44(b)).
        $stores = $this->customerViewStores();
        $contactStoreIds = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);

        return $this->customers->idsMatching($text, $contactStoreIds, $limit);
    }

    public function customerViewStores(): ?array
    {
        // null from storesWith() is every store: the job is a per-store one, never store-free.
        return $this->authorizer->storesWith(AccessPermissions::CUSTOMER_VIEW);
    }

    public function staff(string $staffId): ?StaffDto
    {
        $staff = $this->staff->find($staffId);

        return $staff === null ? null : StaffMapper::toDto($staff);
    }

    public function staffDisplayNames(array $staffIds): array
    {
        return $this->displayNames->forReader($staffIds);
    }

    public function staffNotificationPreferences(string $staffId): array
    {
        if ($this->staff->find($staffId) === null) {
            return [];
        }

        $preferences = [];

        foreach ($this->preferences->of(strtolower($staffId)) as $topic => $toggles) {
            $preferences[] = new StaffNotificationPreferenceDto(StaffNotificationTopic::from($topic), $toggles['email'], $toggles['panel']);
        }

        return $preferences;
    }

    /**
     * An address in an off store is hidden, not deleted (amendment 53): it answers as no address
     * until the store is on again.
     */
    public function address(string $addressId): ?AddressDto
    {
        $address = $this->addresses->find($addressId);

        return $address === null || ! $this->openStores->isOn($address->storeId()) ? null : $this->addressMapper->toDto($address);
    }

    /**
     * None while the store is off (amendment 53); they come back as they were when it is on again.
     */
    public function addresses(string $customerId, string $storeId): array
    {
        if (! $this->openStores->isOn($storeId)) {
            return [];
        }

        return $this->addressMapper->toDtos($this->addresses->forCustomerInStore($customerId, $storeId));
    }

    public function isCustomerEmail(string $email): bool
    {
        try {
            return $this->customers->emailInUse(EmailAddress::of($email));
        } catch (InvalidAccessAttribute) {
            return false;
        }
    }

    public function customersRegistered(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from >= $to) {
            throw new InvalidArgumentException('A range of registrations ends after it starts.');
        }

        return $this->customers->countRegistered($store->value, $from, $to);
    }

    public function withdrawMarketingConsent(string $customerId, MarketingConsentSource $source): void
    {
        $this->withdrawConsent->handle(new WithdrawMarketingConsent($customerId, $source));
    }
}

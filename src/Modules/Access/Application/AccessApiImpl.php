<?php

declare(strict_types=1);

namespace Modules\Access\Application;

use Modules\Access\Application\Address\AddressMapper;
use Modules\Access\Application\Customer\CustomerMapper;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Dto\StaffNotificationPreferenceDto;
use Modules\Access\Public\Enums\StaffNotificationTopic;

final readonly class AccessApiImpl implements AccessApi
{
    public function __construct(
        private StaffUserRepository $staff,
        private NotificationPreferenceRepository $preferences,
        private CustomerRepository $customers,
        private CustomerMapper $customerMapper,
        private AddressRepository $addresses,
        private AddressMapper $addressMapper,
    ) {}

    public function customer(string $customerId): ?CustomerDto
    {
        $customer = $this->customers->find($customerId);

        return $customer === null ? null : $this->customerMapper->toDto($customer);
    }

    public function customerMayOrder(string $customerId): bool
    {
        return $this->customers->find($customerId)?->mayOrder() ?? false;
    }

    public function staff(string $staffId): ?StaffDto
    {
        $staff = $this->staff->find($staffId);

        return $staff === null ? null : StaffMapper::toDto($staff);
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

    public function address(string $addressId): ?AddressDto
    {
        $address = $this->addresses->find($addressId);

        return $address === null ? null : $this->addressMapper->toDto($address);
    }

    public function addresses(string $customerId, string $storeId): array
    {
        return $this->addressMapper->toDtos($this->addresses->forCustomerInStore($customerId, $storeId));
    }
}

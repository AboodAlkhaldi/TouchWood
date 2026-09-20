<?php

declare(strict_types=1);

namespace Modules\Access\Application\Audit;

use Modules\Access\Domain\Model\Address;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Audit entries for a customer's addresses (spec §3.3): every field of an address is personal data,
 * so the log keeps only that it changed — never a street or a recipient's name. The entry sits on
 * the customer, so their history reads as one story, and names the address's own store, so that
 * store's staff can see it.
 */
final class AddressAudit
{
    private const string SUBJECT = 'access.customer';

    public static function added(Address $address): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->personal('label')
            ->personal('recipient_name')
            ->personal('phone')
            ->personal('fields')
            ->changed('is_default', null, $address->isDefault());

        return self::entry('access.customer.address_added', $address, $changes);
    }

    /**
     * @param  list<string>  $changed  Address::pullChanges()
     */
    public static function updated(Address $address, array $changed): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach ($changed as $attribute) {
            match ($attribute) {
                'is_default' => $changes->changed('is_default', ! $address->isDefault(), $address->isDefault()),
                default => $changes->personal($attribute),
            };
        }

        return self::entry('access.customer.address_updated', $address, $changes);
    }

    public static function deleted(Address $address): AuditEntryDto
    {
        return self::entry('access.customer.address_deleted', $address, AuditChanges::none()
            ->personal('label')
            ->personal('recipient_name')
            ->personal('phone')
            ->personal('fields')
            ->changed('is_default', $address->isDefault(), null));
    }

    private static function entry(string $action, Address $address, AuditChanges $changes): AuditEntryDto
    {
        return new AuditEntryDto($action, self::SUBJECT, $address->customerId(), $address->storeId(), $changes);
    }
}

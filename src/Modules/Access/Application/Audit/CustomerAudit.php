<?php

declare(strict_types=1);

namespace Modules\Access\Application\Audit;

use Modules\Access\Domain\Model\Customer;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Audit entries for customer accounts (amendment 37): their own account events are recorded —
 * registration, the verifications, a new phone or password, blocking and deletion — never their
 * sign-ins or browsing. Names, email and phone are personal: recorded only as "changed" (spec
 * §1.1, §3.3). The entry names the store the account belongs to, so a store's staff see it.
 */
final class CustomerAudit
{
    private const string SUBJECT = 'access.customer';

    public static function registered(Customer $customer): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->personal('first_name')
            ->personal('last_name')
            ->personal('email')
            ->changed('account_type', null, $customer->accountType()->value)
            ->changed('status', null, $customer->status()->value)
            ->changed('locale', null, $customer->language()->value)
            ->changed('terms_version', null, $customer->termsVersion());

        return self::entry('access.customer.registered', $customer, $changes);
    }

    /**
     * @param  list<string>  $changed  Customer::pullChanges()
     */
    public static function updated(string $action, Customer $before, Customer $after, array $changed): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach ($changed as $attribute) {
            match ($attribute) {
                'name' => $changes->personal('first_name')->personal('last_name'),
                'locale' => $changes->changed('locale', $before->language()->value, $after->language()->value),
                'last_store_id' => $changes->changed('last_store_id', $before->lastStoreId(), $after->lastStoreId()),
                'email_verified_at' => $changes->changed('email_verified', false, true),
                default => $changes->personal($attribute),
            };
        }

        return self::entry($action, $after, $changes);
    }

    /**
     * Blocking, unblocking, and a deletion asked for or called off (spec §1.10, §3.3). A reason is
     * recorded when staff act for the customer (amendment 43): it is what support was told, and it
     * is kept nowhere but here.
     */
    public static function statusChanged(string $action, Customer $customer, ?string $reason = null): AuditEntryDto
    {
        $changes = AuditChanges::none()->changed('status', null, $customer->status()->value);

        if ($reason !== null) {
            $changes->changed('reason', null, $reason);
        }

        return self::entry($action, $customer, $changes);
    }

    /**
     * A deletion scheduled or called off. The date is not personal data; who asked is the actor.
     */
    public static function deletion(string $action, Customer $customer, ?string $reason = null): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('deletion_scheduled_for', null, $customer->deletionScheduledFor()?->format('Y-m-d H:i:sP'));

        if ($reason !== null) {
            $changes->changed('reason', null, $reason);
        }

        return self::entry($action, $customer, $changes);
    }

    /**
     * The deletion ran (spec §1.10): every field that named a person is gone. The entry says which
     * ones changed and nothing of what they held — the log outlives the account.
     */
    public static function anonymized(Customer $customer): AuditEntryDto
    {
        return self::entry('access.customer.anonymized', $customer, AuditChanges::none()
            ->personal('first_name')
            ->personal('last_name')
            ->personal('email')
            ->personal('phone')
            ->personal('addresses')
            ->changed('anonymized', false, true));
    }

    private static function entry(string $action, Customer $customer, AuditChanges $changes): AuditEntryDto
    {
        return new AuditEntryDto($action, self::SUBJECT, $customer->id(), $customer->homeStoreId(), $changes);
    }
}

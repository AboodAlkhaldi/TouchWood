<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Access\Application\Command\AnonymizeDueAccounts\AnonymizeDueAccounts;
use Modules\Access\Application\Command\AnonymizeDueAccounts\AnonymizeDueAccountsHandler;
use Modules\Access\Application\Command\BlockCustomer\BlockCustomer;
use Modules\Access\Application\Command\BlockCustomer\BlockCustomerHandler;
use Modules\Access\Application\Command\CancelAccountDeletion\CancelAccountDeletion;
use Modules\Access\Application\Command\CancelAccountDeletion\CancelAccountDeletionHandler;
use Modules\Access\Application\Command\CancelCustomerDeletion\CancelCustomerDeletion;
use Modules\Access\Application\Command\CancelCustomerDeletion\CancelCustomerDeletionHandler;
use Modules\Access\Application\Command\DeleteCustomerOnRequest\DeleteCustomerOnRequest;
use Modules\Access\Application\Command\DeleteCustomerOnRequest\DeleteCustomerOnRequestHandler;
use Modules\Access\Application\Command\RequestAccountDeletion\RequestAccountDeletion;
use Modules\Access\Application\Command\RequestAccountDeletion\RequestAccountDeletionHandler;
use Modules\Access\Application\Command\UnblockCustomer\UnblockCustomer;
use Modules\Access\Application\Command\UnblockCustomer\UnblockCustomerHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\AccountLocked;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCustomerStatus;
use Modules\Access\Domain\Model\Customer;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Access\Public\Events\CustomerBlocked;
use Modules\Access\Public\Events\CustomerDeletionCancelled;
use Modules\Access\Public\Events\CustomerDeletionScheduled;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function deletionRow(string $customerId): ?stdClass
{
    return DB::table('access.customers')->where('id', $customerId)->first();
}

function deletionAddress(string $customerId): void
{
    DB::table('access.addresses')->insert([
        'id' => strtolower((string) Str::ulid()),
        'customer_id' => $customerId,
        'store_id' => Fx::storeId('sa'),
        'label' => 'Home',
        'recipient_name' => 'Sara Ali',
        'phone' => '+966501234567',
        'fields' => json_encode(['city' => 'Riyadh']),
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A staff member who may block and delete customers of one store (both admin-only, amendment 43).
 */
function customerAdmin(string $storeCode = 'sa'): string
{
    return Fx::actAsAdmin([$storeCode], [AccessPermissions::CUSTOMER_BLOCK, AccessPermissions::CUSTOMER_DELETE, AccessPermissions::CUSTOMER_VIEW]);
}

describe('a customer deleting their own account (spec §1.10)', function () {
    it('schedules it 14 days out, stops them ordering, and tells them once', function () {
        Event::fake([CustomerDeletionScheduled::class]);
        $customerId = Fx::customer();
        // Verified both ways, so they could order until they asked for this.
        DB::table('access.customers')->where('id', $customerId)
            ->update(['email_verified_at' => now(), 'phone' => '+966500000001', 'phone_verified_at' => now()]);
        expect(app(AccessApi::class)->customerMayOrder($customerId))->toBeTrue();
        Fx::actAsCustomer($customerId);

        Fx::inStoreCode('sa', fn () => app(RequestAccountDeletionHandler::class)->handle(new RequestAccountDeletion(Fx::CUSTOMER_PASSWORD, '10.0.0.1')));
        defer()->invoke();

        $row = deletionRow($customerId);

        expect($row?->deletion_scheduled_for)->not->toBeNull()
            ->and(CarbonImmutable::parse((string) $row?->deletion_scheduled_for)->toDateString())
            ->toBe(CarbonImmutable::now()->addDays(14)->toDateString())
            ->and(app(AccessApi::class)->customerMayOrder($customerId))->toBeFalse()
            ->and(RecordingSecurityMessages::installed()->deletions)->toHaveCount(1)
            ->and(Fx::audits('access.customer.deletion_scheduled', $customerId))->toBe(1);

        Event::assertDispatched(CustomerDeletionScheduled::class);
    });

    it('refuses a wrong password, and counts it towards the lockout', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);

        foreach (range(1, 4) as $try) {
            try {
                Fx::inStoreCode('sa', fn () => app(RequestAccountDeletionHandler::class)->handle(new RequestAccountDeletion('wrong '.$try, '10.0.0.2')));
            } catch (InvalidAccessAttribute) {
                // Counted, like a wrong password at sign-in.
            }
        }

        // The fifth locks the account, and the wait is the full fifteen minutes from it — not what
        // is left of the count that led there.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(14));

        try {
            Fx::inStoreCode('sa', fn () => app(RequestAccountDeletionHandler::class)->handle(new RequestAccountDeletion('wrong 5', '10.0.0.2')));
        } catch (InvalidAccessAttribute) {
            // The fifth wrong password.
        }

        expect(fn () => Fx::inStoreCode('sa', fn () => app(RequestAccountDeletionHandler::class)->handle(new RequestAccountDeletion(Fx::CUSTOMER_PASSWORD, '10.0.0.2'))))
            ->toThrow(AccountLocked::class, '900 seconds')
            ->and(deletionRow($customerId)?->deletion_scheduled_for)->toBeNull()
            ->and(RecordingSecurityMessages::installed()->deletions)->toBeEmpty();
    });

    it('is called off from the account page', function () {
        Event::fake([CustomerDeletionCancelled::class]);
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        Fx::inStoreCode('sa', fn () => app(RequestAccountDeletionHandler::class)->handle(new RequestAccountDeletion(Fx::CUSTOMER_PASSWORD, '10.0.0.3')));

        app(CancelAccountDeletionHandler::class)->handle(new CancelAccountDeletion);

        expect(deletionRow($customerId)?->deletion_scheduled_for)->toBeNull()
            ->and(Fx::audits('access.customer.deletion_cancelled', $customerId))->toBe(1);

        Event::assertDispatched(CustomerDeletionCancelled::class);
    });

    it('is only for the customer acting', function () {
        Fx::actAsStaff(Fx::staff());

        expect(fn () => Fx::inStoreCode('sa', fn () => app(RequestAccountDeletionHandler::class)->handle(new RequestAccountDeletion(Fx::CUSTOMER_PASSWORD, '10.0.0.4'))))
            ->toThrow(Unauthorized::class);
    });
});

describe('staff acting on a customer (spec §3.3, amendment 43)', function () {
    it('deletes on the customer\'s request, with a reason, and tells them', function () {
        $customerId = Fx::customer();
        customerAdmin();

        app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($customerId, 'Asked on the phone'));
        defer()->invoke();

        $changes = json_decode((string) DB::table('platform.audit_entries')
            ->where('action', 'access.customer.deletion_scheduled')->value('changes'), true);

        expect(deletionRow($customerId)?->deletion_scheduled_for)->not->toBeNull()
            ->and(RecordingSecurityMessages::installed()->deletions)->toHaveCount(1)
            ->and($changes['reason'][1] ?? null)->toBe('Asked on the phone');
    });

    it('needs a reason', function () {
        $customerId = Fx::customer();
        customerAdmin();

        expect(fn () => app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($customerId, '   ')))
            ->toThrow(InvalidAccessAttribute::class, 'reason')
            ->and(deletionRow($customerId)?->deletion_scheduled_for)->toBeNull();
    });

    it('calls a deletion off for a customer who cannot sign in', function () {
        $customerId = Fx::customer();
        customerAdmin();
        app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($customerId, 'Asked on the phone'));

        app(CancelCustomerDeletionHandler::class)->handle(new CancelCustomerDeletion($customerId, 'Changed their mind'));

        expect(deletionRow($customerId)?->deletion_scheduled_for)->toBeNull()
            ->and(Fx::audits('access.customer.deletion_cancelled', $customerId))->toBe(1);
    });

    it('blocks and unblocks, with the reason in the audit log', function () {
        Event::fake([CustomerBlocked::class]);
        $customerId = Fx::customer();
        customerAdmin();

        app(BlockCustomerHandler::class)->handle(new BlockCustomer($customerId, 'Chargeback fraud'));

        expect(deletionRow($customerId)?->status)->toBe('BLOCKED')
            ->and(app(AccessApi::class)->customerMayOrder($customerId))->toBeFalse()
            ->and(Fx::audits('access.customer.blocked', $customerId))->toBe(1);

        Event::assertDispatched(CustomerBlocked::class);

        app(UnblockCustomerHandler::class)->handle(new UnblockCustomer($customerId, 'Bank confirmed the payment'));

        expect(deletionRow($customerId)?->status)->toBe('ACTIVE')
            ->and(Fx::audits('access.customer.unblocked', $customerId))->toBe(1);
    });

    it('refuses a change the account\'s state does not allow', function () {
        $customerId = Fx::customer();
        customerAdmin();

        expect(fn () => app(UnblockCustomerHandler::class)->handle(new UnblockCustomer($customerId, 'Not blocked')))
            ->toThrow(InvalidCustomerStatus::class);

        app(BlockCustomerHandler::class)->handle(new BlockCustomer($customerId, 'Fraud'));

        expect(fn () => app(BlockCustomerHandler::class)->handle(new BlockCustomer($customerId, 'Again')))
            ->toThrow(InvalidCustomerStatus::class);
    });

    it('is refused to staff of another store, and to staff with no such action', function () {
        $customerId = Fx::customer();
        Fx::actAsAdmin(['ae'], [AccessPermissions::CUSTOMER_BLOCK, AccessPermissions::CUSTOMER_DELETE]);

        expect(fn () => app(BlockCustomerHandler::class)->handle(new BlockCustomer($customerId, 'Fraud')))
            ->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']));

        expect(fn () => app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($customerId, 'Asked')))
            ->toThrow(Unauthorized::class);
    });
});

describe('the deletion sweep (spec §1.10, amendment 43)', function () {
    it('anonymizes what is due, and leaves the rest', function () {
        Event::fake([CustomerAnonymized::class]);
        $due = Fx::customer('due@example.test');
        $waiting = Fx::customer('waiting@example.test');
        customerAdmin();
        app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($due, 'Asked'));
        app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($waiting, 'Asked'));
        DB::table('access.customers')->where('id', $due)->update(['deletion_scheduled_for' => CarbonImmutable::now()->subMinute()]);

        $done = Fx::asSystem(fn (): int => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts));

        $row = deletionRow($due);

        expect($done)->toBe(1)
            ->and($row?->anonymized_at)->not->toBeNull()
            ->and($row?->first_name)->toBe(Customer::DELETED_NAME)
            ->and($row?->last_name)->toBe(Customer::DELETED_NAME)
            ->and($row?->email)->toBe("deleted-{$due}@deleted.invalid")
            ->and($row?->phone)->toBeNull()
            ->and($row?->deletion_scheduled_for)->toBeNull()
            ->and((int) $row?->session_version)->toBe(1)
            // What stays: the account type, the home store and the dates (amendment 43).
            ->and($row?->account_type)->toBe('INDIVIDUAL')
            ->and($row?->home_store_id)->toBe(Fx::storeId('sa'))
            ->and(deletionRow($waiting)?->anonymized_at)->toBeNull()
            ->and(Fx::audits('access.customer.anonymized', $due))->toBe(1);

        Event::assertDispatched(CustomerAnonymized::class, fn (CustomerAnonymized $event): bool => $event->customerId === $due);
    });

    it('purges the addresses and anything that could still let them in', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        deletionAddress($customerId);
        DB::table('access.customer_password_resets')->insert([
            'customer_id' => $customerId, 'token_hash' => hash('sha256', 'token'),
            'expires_at' => CarbonImmutable::now()->addHour(), 'created_at' => CarbonImmutable::now(),
        ]);
        customerAdmin();
        app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($customerId, 'Asked'));
        DB::table('access.customers')->where('id', $customerId)->update(['deletion_scheduled_for' => CarbonImmutable::now()->subMinute()]);

        Fx::asSystem(fn (): int => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts));

        expect(DB::table('access.addresses')->where('customer_id', $customerId)->count())->toBe(0)
            ->and(DB::table('access.customer_password_resets')->where('customer_id', $customerId)->count())->toBe(0);
    });

    it('frees the old email for a new account', function () {
        $customerId = Fx::customer('sara@example.test');
        customerAdmin();
        app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($customerId, 'Asked'));
        DB::table('access.customers')->where('id', $customerId)->update(['deletion_scheduled_for' => CarbonImmutable::now()->subMinute()]);
        Fx::asSystem(fn (): int => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts));

        $again = Fx::customer('sara@example.test');

        expect($again)->not->toBe($customerId)
            ->and(DB::table('access.customers')->count())->toBe(2);
    });

    it('does nothing to an account whose deletion was called off', function () {
        $customerId = Fx::customer();
        customerAdmin();
        app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($customerId, 'Asked'));
        DB::table('access.customers')->where('id', $customerId)->update(['deletion_scheduled_for' => CarbonImmutable::now()->subMinute()]);
        app(CancelCustomerDeletionHandler::class)->handle(new CancelCustomerDeletion($customerId, 'Changed their mind'));

        $done = Fx::asSystem(fn (): int => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts));

        expect($done)->toBe(0)
            ->and(deletionRow($customerId)?->anonymized_at)->toBeNull();
    });

    it('checks again under the lock, so a deletion stopped meanwhile is not run', function () {
        $customerId = Fx::customer();
        customerAdmin();
        app(DeleteCustomerOnRequestHandler::class)->handle(new DeleteCustomerOnRequest($customerId, 'Asked'));
        DB::table('access.customers')->where('id', $customerId)->update(['deletion_scheduled_for' => CarbonImmutable::now()->subMinute()]);

        // The sweep reads the list; the customer signs in before it takes the row's lock.
        $real = app(CustomerRepository::class);
        $customers = Mockery::mock(CustomerRepository::class);
        $customers->shouldReceive('dueForAnonymizing')->andReturnUsing(function () use ($customerId, $real): array {
            $due = $real->dueForAnonymizing(CarbonImmutable::now(), 500);
            DB::table('access.customers')->where('id', $customerId)->update(['deletion_scheduled_for' => null]);

            return $due;
        });
        $customers->shouldReceive('byId')->andReturnUsing(fn (string $id) => $real->byId($id));

        $done = Fx::asSystem(fn (): int => app()->make(AnonymizeDueAccountsHandler::class, ['customers' => $customers])
            ->handle(new AnonymizeDueAccounts));

        expect($done)->toBe(0)
            ->and(deletionRow($customerId)?->anonymized_at)->toBeNull()
            ->and(deletionRow($customerId)?->email)->toBe('sara@example.test');
    });

    it('is refused to anyone but the system', function () {
        customerAdmin();

        expect(fn () => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts))
            ->toThrow(Unauthorized::class);
    });
});

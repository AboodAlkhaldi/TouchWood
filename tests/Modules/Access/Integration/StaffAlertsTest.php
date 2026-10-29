<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Command\SetOwnCriticalSms\SetOwnCriticalSms;
use Modules\Access\Application\Command\SetOwnCriticalSms\SetOwnCriticalSmsHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\StaffAlertRecipientDto;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Ops step 4 - Access, part B: who receives a staff alert, in one read (amendment 80); the critical-SMS
| switch (81); the Returns and Payments to check topics (82, 84); the stores a staff member may view,
| for Ops' staff bell (88).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function alertStore(string $code): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/**
 * @return list<string>
 */
function alertIds(string $storeCode, string $permission, StaffNotificationTopic $topic = StaffNotificationTopic::Returns): array
{
    $ids = array_map(static fn (StaffAlertRecipientDto $recipient): string => $recipient->staffId, app(AccessApi::class)->staffToAlert(alertStore($storeCode), $topic, $permission));
    sort($ids);

    return $ids;
}

/**
 * @param  list<string>  $ids
 * @return list<string>
 */
function alertSorted(array $ids): array
{
    sort($ids);

    return $ids;
}

/**
 * @return list<string>
 */
function alertStoreCodes(string $staffId): array
{
    $codes = DB::table('platform.stores')->pluck('code', 'id')->all();

    return array_map(static fn (StoreId $store): string => (string) $codes[$store->value], app(AccessApi::class)->staffStores($staffId));
}

describe('staffToAlert (amendment 80)', function () {
    it('answers the active holders of the permission in that store, Super Admins always among them', function () {
        $saReturns = Fx::staffWith(['sales.return.manage'], ['sa']);
        $aeReturns = Fx::staffWith(['sales.return.manage'], ['ae']);
        $everywhere = Fx::staffWith(['sales.return.manage'], ['*']);
        $saOther = Fx::staffWith(['sales.order.view'], ['sa']);
        $disabled = Fx::staffWith(['sales.return.manage'], ['sa']);
        DB::table('access.staff_users')->where('id', $disabled)->update(['status' => StaffStatus::Disabled->value]);
        $super = Fx::staff(superAdmin: true);
        Fx::staff(StaffStatus::Disabled, superAdmin: true);

        expect(alertIds('sa', 'sales.return.manage'))->toBe(alertSorted([$saReturns, $everywhere, $super]))
            ->and(alertIds('ae', 'sales.return.manage'))->toBe(alertSorted([$aeReturns, $everywhere, $super]))
            ->and(alertIds('sa', 'sales.order.view'))->toBe(alertSorted([$saOther, $super]));
    });

    it('narrows an action by its own stores, and an admin-only one to admin roles', function () {
        // Their row is both stores; the action itself reaches only the UAE.
        $narrowed = Fx::staffWith(['sales.return.manage'], ['sa', 'ae'], exceptions: ['sales.return.manage' => ['ae']]);
        $admin = Fx::staffWith([AccessPermissions::CUSTOMER_BLOCK], ['sa'], RoleLevel::Admin);
        $super = Fx::staff(superAdmin: true);

        expect(alertIds('sa', 'sales.return.manage'))->toBe([$super])
            ->and(alertIds('ae', 'sales.return.manage'))->toBe(alertSorted([$narrowed, $super]))
            ->and(alertIds('sa', AccessPermissions::CUSTOMER_BLOCK))->toBe(alertSorted([$admin, $super]))
            ->and(alertIds('ae', AccessPermissions::CUSTOMER_BLOCK))->toBe([$super]);
    });

    it('holds a store-free action in every store once it is held, as the permission check does', function () {
        $admin = Fx::staffWith([AccessPermissions::ROLE_MANAGE], ['sa'], RoleLevel::Admin);
        $super = Fx::staff(superAdmin: true);

        expect(alertIds('ae', AccessPermissions::ROLE_MANAGE))->toBe(alertSorted([$admin, $super]));
    });

    it('sends a reserved permission to Super Admins only, and an automatic staff one to every active staff member', function () {
        $staff = Fx::staffWith(['sales.return.manage'], ['sa']);
        $noRole = Fx::staff();
        $super = Fx::staff(superAdmin: true);

        expect(alertIds('sa', AccessPermissions::SUPER_ADMIN_MANAGE))->toBe([$super])
            ->and(alertIds('sa', AccessPermissions::OWN_ACCOUNT_UPDATE))->toBe(alertSorted([$staff, $noRole, $super]));
    });

    it('still alerts an off store\'s staff', function () {
        $staff = Fx::staffWith(['sales.return.manage'], ['eg']);
        $eg = alertStore('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        $recipients = app(AccessApi::class)->staffToAlert($eg, StaffNotificationTopic::Returns, 'sales.return.manage');
        expect(array_map(static fn (StaffAlertRecipientDto $recipient): string => $recipient->staffId, $recipients))->toBe([$staff]);
    });

    it('carries each person\'s switches for the topic, a topic with no row reading as its default', function () {
        $staff = Fx::staffWith(['sales.payment.record'], ['sa']);
        DB::table('access.staff_users')->where('id', $staff)->update(['locale' => 'en']);
        DB::table('access.staff_notification_preferences')->insert(
            ['staff_user_id' => $staff, 'topic' => StaffNotificationTopic::PaymentsToCheck->value, 'email' => true, 'panel' => false],
        );
        $row = DB::table('access.staff_users')->where('id', $staff)->first();

        $toCheck = app(AccessApi::class)->staffToAlert(alertStore('sa'), StaffNotificationTopic::PaymentsToCheck, 'sales.payment.record');
        $returns = app(AccessApi::class)->staffToAlert(alertStore('sa'), StaffNotificationTopic::Returns, 'sales.payment.record');

        expect($toCheck)->toEqual([new StaffAlertRecipientDto($staff, 'en', (string) $row?->email, $row?->phone, byPanel: false, byEmail: true, criticalSms: true)])
            ->and([$returns[0]->byPanel, $returns[0]->byEmail])->toBe([true, false]);
    });

    it('reads everyone in one query, however many there are', function () {
        foreach (range(1, 5) as $ignored) {
            Fx::staffWith(['sales.return.manage'], ['sa']);
        }

        $sa = alertStore('sa');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $recipients = app(AccessApi::class)->staffToAlert($sa, StaffNotificationTopic::Returns, 'sales.return.manage');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($recipients)->toHaveCount(5)->and($queries)->toHaveCount(1);
    });

    it('refuses a permission no module declares', function () {
        expect(fn () => app(AccessApi::class)->staffToAlert(alertStore('sa'), StaffNotificationTopic::Returns, 'sales.return.gossip'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('the critical-SMS switch (amendment 81)', function () {
    it('is on by default, and a Super Admin switches their own, audited once per change', function () {
        $super = Fx::staff(superAdmin: true);
        Fx::actAsStaff($super);

        $recipient = static fn (): StaffAlertRecipientDto => app(AccessApi::class)->staffToAlert(alertStore('sa'), StaffNotificationTopic::PaymentProblems, AccessPermissions::SUPER_ADMIN_MANAGE)[0];
        expect($recipient()->criticalSms)->toBeTrue();

        app(SetOwnCriticalSmsHandler::class)->handle(new SetOwnCriticalSms(false));
        app(SetOwnCriticalSmsHandler::class)->handle(new SetOwnCriticalSms(false));

        expect($recipient()->criticalSms)->toBeFalse()
            ->and(Fx::audits('access.staff_user.critical_sms_changed', $super))->toBe(1);

        app(SetOwnCriticalSmsHandler::class)->handle(new SetOwnCriticalSms(true));
        expect($recipient()->criticalSms)->toBeTrue()
            ->and(Fx::audits('access.staff_user.critical_sms_changed', $super))->toBe(2);
    });

    it('is a Super Admin\'s only', function () {
        $admin = Fx::actAsAdmin(['*'], [AccessPermissions::STAFF_VIEW]);

        expect(fn () => app(SetOwnCriticalSmsHandler::class)->handle(new SetOwnCriticalSms(false)))->toThrow(Unauthorized::class)
            ->and((bool) DB::table('access.staff_users')->where('id', $admin)->value('critical_sms'))->toBeTrue()
            ->and(Fx::audits('access.staff_user.critical_sms_changed'))->toBe(0);
    });
});

describe('the two new topics (amendments 82, 84)', function () {
    it('are named in both languages', function () {
        expect(StaffNotificationTopic::Returns->value)->toBe('RETURNS')
            ->and(StaffNotificationTopic::PaymentsToCheck->value)->toBe('PAYMENTS_TO_CHECK')
            ->and(__('access::account.topic.RETURNS', [], 'en'))->toBe('Returns')
            ->and(__('access::account.topic.RETURNS', [], 'ar'))->toBe('المرتجعات')
            ->and(__('access::account.topic.PAYMENTS_TO_CHECK', [], 'en'))->toBe('Payments to Check')
            ->and(__('access::account.topic.PAYMENTS_TO_CHECK', [], 'ar'))->toBe('مدفوعات للمراجعة');
    });

    it('take their rows away when undone, and the topic CHECK with them', function () {
        $staff = Fx::staff();
        DB::table('access.staff_notification_preferences')->insert([
            ['staff_user_id' => $staff, 'topic' => 'RETURNS', 'email' => true, 'panel' => true],
            ['staff_user_id' => $staff, 'topic' => 'PAYMENTS_TO_CHECK', 'email' => true, 'panel' => true],
        ]);
        $migrations = base_path('src/Modules/Access/Infrastructure/Persistence/Migrations');

        (require "{$migrations}/2026_10_29_110001_add_payments_to_check_topic.php")->down();
        (require "{$migrations}/2026_10_29_110000_add_returns_topic.php")->down();

        expect(DB::table('access.staff_notification_preferences')->whereIn('topic', ['RETURNS', 'PAYMENTS_TO_CHECK'])->count())->toBe(0)
            ->and(fn () => DB::table('access.staff_notification_preferences')->insert(['staff_user_id' => $staff, 'topic' => 'RETURNS', 'email' => true, 'panel' => true]))
            ->toThrow(QueryException::class, 'staff_notification_preferences_topic');
    });
});

describe('staffStores (amendment 88)', function () {
    it('answers a Super Admin every store, off ones too, and anyone else their assigned stores that are on', function () {
        $staff = Fx::staffWith(['sales.order.view'], ['sa', 'eg']);
        $super = Fx::staff(superAdmin: true);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $all = DB::table('platform.stores')->count();

        expect(alertStoreCodes($staff))->toBe(['sa'])
            ->and(app(AccessApi::class)->staffStores($super))->toHaveCount($all);
    });

    it('answers none for an account that is not active, has no role, or is no one\'s', function () {
        $disabled = Fx::staffWith(['sales.order.view'], ['sa']);
        DB::table('access.staff_users')->where('id', $disabled)->update(['status' => StaffStatus::Disabled->value]);
        app(GrantsReader::class)->refresh($disabled);

        expect(app(AccessApi::class)->staffStores($disabled))->toBe([])
            ->and(app(AccessApi::class)->staffStores(Fx::staff()))->toBe([])
            ->and(app(AccessApi::class)->staffStores('01j8z3k4m5n6p7q8r9s0t1v2w3'))->toBe([])
            ->and(app(AccessApi::class)->staffStores('not an id'))->toBe([]);
    });

    it('follows a change of assignment at once', function () {
        $staff = Fx::staffWith(['sales.order.view'], ['sa']);
        expect(alertStoreCodes($staff))->toBe(['sa']);

        Fx::assign($staff, Fx::roleOf($staff), ['ae']);

        expect(alertStoreCodes($staff))->toBe(['ae']);
    });
});

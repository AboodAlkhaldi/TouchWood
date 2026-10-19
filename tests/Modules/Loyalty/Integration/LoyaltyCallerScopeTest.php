<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Authorization\InvalidPermissionCheck;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Public\Dto\CallerPermission;
use Modules\Loyalty\Public\Dto\EarnPoints;
use Modules\Loyalty\Public\Dto\RedeemPoints;
use Modules\Loyalty\Public\Enums\Audience;
use Shared\Application\Actor;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| The caller's permission (loyalty.md §2.1, §8 Permissions and scope): the calling module's own,
| checked in the store recorded on the order whatever store was passed; a mismatch refused; so is
| another module's permission, or Loyalty's own.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Lx::programme();
    Lx::programme('eg');
});

function loyaltyScopeEarn(string $customerId, string $orderId, string $storeCode, ?CallerPermission $caller = null): void
{
    DB::transaction(fn () => Lx::api()->earn(new EarnPoints(
        $orderId, 'T-1', $customerId, Fx::storeId($storeCode), Lx::money(10_000, $storeCode), $caller ?? Lx::delivering(),
    )));
}

/**
 * Every check asked of the authorizer, by name and scope; each allowed.
 *
 * @return ArrayObject<int, string>
 */
function loyaltyScopeRecorder(): ArrayObject
{
    /** @var ArrayObject<int, string> $asked */
    $asked = new ArrayObject;
    app()->forgetScopedInstances();
    app()->instance(Authorizer::class, new class($asked) implements Authorizer
    {
        /** @param ArrayObject<int, string> $asked */
        public function __construct(private ArrayObject $asked) {}

        public function authorize(string $permission, PermissionScope $scope): void
        {
            $this->asked->append("{$permission} @ {$scope->describe()}");
        }

        public function storesWith(string $permission): ?array
        {
            return null;
        }

        public function isUnlimited(): bool
        {
            return true;
        }
    });

    return $asked;
}

it('checks the caller\'s permission in the order\'s store', function () {
    $customer = Fx::customer();
    $order = Lx::orderId();
    $asked = loyaltyScopeRecorder();

    loyaltyScopeEarn($customer, $order, 'sa');

    expect($asked->getArrayCopy())->toBe([Lx::FULFIL.' @ '.Fx::storeId('sa')]);
});

it('refuses a store passed in that is not the order\'s recorded store, before checking anything', function () {
    $customer = Fx::customer();
    $order = Lx::orderId();
    Fx::asSystem(fn () => loyaltyScopeEarn($customer, $order, 'sa'));
    $asked = loyaltyScopeRecorder();

    try {
        loyaltyScopeEarn($customer, $order, 'eg');
    } catch (InvalidPointsAttribute $refused) {
        expect($refused->attribute)->toBe('store')
            ->and($asked->getArrayCopy())->toBe([]);

        return;
    }

    throw new RuntimeException('Nothing was refused.');
});

it('lets a staff member deliver only where they hold the job', function () {
    $customer = Fx::customer();
    Fx::actAsStaff(Fx::staffWith([Lx::FULFIL], ['eg']));

    expect(fn () => loyaltyScopeEarn($customer, Lx::orderId(), 'sa'))->toThrow(Unauthorized::class)
        ->and(DB::table('loyalty.orders')->count())->toBe(0);

    Fx::actAsStaff(Fx::staffWith([Lx::FULFIL], ['sa']));
    loyaltyScopeEarn($customer, Lx::orderId(), 'sa');

    expect(DB::table('loyalty.orders')->count())->toBe(1);
});

it('lets a customer place with their own permission, and a guest not', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 30_000);
    $quote = Lx::quote($customer, 100, 100_000);
    $redeem = new RedeemPoints(Lx::orderId(), 'T-1', $customer, Fx::storeId('sa'), $quote->pointsCharged, $quote->discount, Audience::Public, Lx::money(100_000), Lx::placing());

    Fx::actAs(Actor::guest(Lx::orderId()));

    expect(fn () => DB::transaction(fn () => Lx::api()->redeem($redeem)))->toThrow(Unauthorized::class);

    Fx::actAsCustomer($customer);
    DB::transaction(fn () => Lx::api()->redeem($redeem));

    expect(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->count())->toBe(1);
});

it('refuses a permission that is not the calling module\'s own, or is Loyalty\'s', function (CallerPermission $caller) {
    $customer = Fx::customer();

    try {
        Fx::asSystem(fn () => loyaltyScopeEarn($customer, Lx::orderId(), 'sa', $caller));
    } catch (InvalidPointsAttribute $refused) {
        expect($refused->attribute)->toBe('permission')
            ->and(DB::table('loyalty.orders')->count())->toBe(0);

        return;
    }

    throw new RuntimeException('Nothing was refused.');
})->with([
    'another module\'s' => [new CallerPermission('sales', 'catalog.product.update')],
    'a name that only begins like the module' => [new CallerPermission('sales', 'salesman.order.fulfil')],
    'Loyalty\'s own' => [new CallerPermission('loyalty', 'loyalty.points.adjust')],
]);

it('leaves an undeclared or store-free permission to the authorizer, which refuses it', function () {
    $customer = Fx::customer();

    expect(fn () => Fx::asSystem(fn () => loyaltyScopeEarn($customer, Lx::orderId(), 'sa', new CallerPermission('sales', 'sales.nothing'))))
        ->toThrow(InvalidPermissionCheck::class)
        ->and(fn () => Fx::asSystem(fn () => loyaltyScopeEarn($customer, Lx::orderId(), 'sa', new CallerPermission('sales', Lx::SYSTEM))))
        ->toThrow(InvalidPermissionCheck::class, 'store-free');
});

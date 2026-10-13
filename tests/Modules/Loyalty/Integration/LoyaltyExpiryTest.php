<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Loyalty\Application\Command\ExpirePoints\ExpirePoints;
use Modules\Loyalty\Application\Command\ExpirePoints\ExpirePointsHandler;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Infrastructure\Queue\ExpirePointsJob;
use Psr\Log\AbstractLogger;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| The nightly expiry (loyalty.md §1.10, §8 Expiry): as the system alone, at 00:30 UTC, one account at a
| time under its lock — exactly the live lots whose date passed, one entry each; an empty lot needs
| none.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Lx::standInForSales();
    Lx::programme();
    Lx::programme('eg');
    CarbonImmutable::setTestNow('2026-06-01 12:00:00 UTC');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function loyaltyExpirySweep(): int
{
    return Fx::asSystem(fn (): int => app(ExpirePointsHandler::class)->handle(new ExpirePoints));
}

it('expires exactly the dead lots with points left, one entry each, store by store', function () {
    $dying = Fx::customer();
    $spent = Fx::customer('spent@example.test');
    $living = Fx::customer('living@example.test');
    $egypt = Fx::customer('egypt@example.test', 'eg');
    Lx::deliver($dying, 30_000);
    Lx::deliver($spent, 10_000);
    Lx::deliver($egypt, 20_000, 'eg');
    // The spent customer used every point: their lot dies empty.
    Lx::place($spent, Lx::quote($spent, 100, 100_000), 100_000);
    CarbonImmutable::setTestNow('2026-12-01 12:00:00 UTC');
    Lx::deliver($dying, 5_000);
    Lx::deliver($living, 5_000);
    // A year and a day after June: the June lots are dead, December's live.
    CarbonImmutable::setTestNow('2027-06-02 12:00:00 UTC');

    $swept = loyaltyExpirySweep();

    $expired = DB::table('loyalty.entries')->where('kind', 'EXPIRED')->join('loyalty.accounts', 'loyalty.accounts.id', '=', 'loyalty.entries.account_id')
        ->orderBy('loyalty.entries.id')->get(['loyalty.accounts.customer_id', 'loyalty.entries.points'])
        ->map(fn (stdClass $entry): array => [(string) $entry->customer_id, (int) $entry->points])->all();

    expect($swept)->toBe(2)
        ->and($expired)->toEqualCanonicalizing([[$dying, -300], [$egypt, -200]])
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $dying)->value('balance'))->toBe(50)
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $living)->value('balance'))->toBe(50)
        // Swept again: nothing left to expire.
        ->and(loyaltyExpirySweep())->toBe(0)
        ->and(DB::table('loyalty.entries')->where('kind', 'EXPIRED')->count())->toBe(2);
});

it('reads the accounts with dead lots a page at a time, in id order', function () {
    $customers = [Fx::customer(), Fx::customer('two@example.test'), Fx::customer('three@example.test')];

    foreach ($customers as $customer) {
        Lx::deliver($customer, 10_000);
    }

    CarbonImmutable::setTestNow('2027-06-02 12:00:00 UTC');
    $accounts = app(PointsAccounts::class);
    $first = $accounts->withDeadLots(CarbonImmutable::now(), null, 2);
    $rest = $accounts->withDeadLots(CarbonImmutable::now(), $first[1]->id, 2);
    $ids = DB::table('loyalty.accounts')->orderBy('id')->pluck('id')->all();

    expect(array_map(static fn ($account): string => $account->id, [...$first, ...$rest]))->toBe($ids)
        ->and($rest)->toHaveCount(1)
        // Before their date, none.
        ->and($accounts->withDeadLots(CarbonImmutable::parse('2027-05-31 12:00:00 UTC'), null, 10))->toBe([]);
});

it('sweeps every page, and passes over an account that fails without keeping the others waiting', function () {
    $customers = [Fx::customer(), Fx::customer('two@example.test'), Fx::customer('three@example.test'), Fx::customer('four@example.test'), Fx::customer('five@example.test')];

    foreach ($customers as $customer) {
        Lx::deliver($customer, 10_000);
    }

    CarbonImmutable::setTestNow('2027-06-02 12:00:00 UTC');
    $real = app(PointsAccounts::class);
    // The second account in id order fails; pages of 2, so the sweep needs three of them.
    $failing = (string) DB::table('loyalty.accounts')->orderBy('id')->skip(1)->value('customer_id');
    $accounts = new class($real, $failing) implements PointsAccounts
    {
        public function __construct(private PointsAccounts $real, private string $failing) {}

        public function find(string $customerId, StoreId $store): ?PointsAccount
        {
            return $this->real->find($customerId, $store);
        }

        public function lockOrCreate(string $customerId, StoreId $store, DateTimeImmutable $now): PointsAccount
        {
            return $customerId === $this->failing ? throw new RuntimeException('Stuck.') : $this->real->lockOrCreate($customerId, $store, $now);
        }

        public function liveLots(PointsAccount $account, DateTimeImmutable $now): array
        {
            return $this->real->liveLots($account, $now);
        }

        public function liveBalance(PointsAccount $account, DateTimeImmutable $now): int
        {
            return $this->real->liveBalance($account, $now);
        }

        public function withDeadLots(DateTimeImmutable $now, ?string $after, int $limit): array
        {
            return $this->real->withDeadLots($now, $after, $limit);
        }
    };
    $log = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $errors = [];

        /**
         * @param  mixed  $level
         * @param  array<mixed>  $context
         */
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->errors[] = (string) $message;
        }
    };
    $handler = new ExpirePointsHandler(app(Authorizer::class), app(ActorContext::class), $accounts, DB::connection(), $log, 2);

    $swept = Fx::asSystem(fn (): int => $handler->handle(new ExpirePoints));

    // Tried once: the pages move on past it, never back to it.
    expect($swept)->toBe(4)
        ->and($log->errors)->toBe(['A points account could not be swept.'])
        ->and(DB::table('loyalty.entries')->where('kind', 'EXPIRED')->count())->toBe(4)
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $failing)->value('balance'))->toBe(100);
});

it('is queued by the scheduler once a day, at 00:30 UTC, from one server, one at a time', function () {
    Queue::fake();
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => $event->description === ExpirePointsJob::class);

    // 00:30 UTC is 03:30 in Riyadh, after Access's 00:00 sweep (§1.10).
    expect(config('app.timezone'))->toBe('UTC')
        ->and($events)->toHaveCount(1)
        ->and($events->first()?->expression)->toBe('30 0 * * *')
        ->and($events->first()?->onOneServer)->toBeTrue()
        ->and(new ExpirePointsJob)->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new ExpirePointsJob)->tries)->toBe(1);

    $events->first()?->run(app());

    Queue::assertPushed(ExpirePointsJob::class, 1);
});

it('is the system\'s alone: never an admin, a Super Admin, or a job queued for one', function (Closure $actor) {
    Fx::actAs($actor());

    expect(fn () => app(ExpirePointsHandler::class)->handle(new ExpirePoints))->toThrow(Unauthorized::class);
})->with([
    'an admin with every points job' => fn () => Actor::staff(Fx::staffWith(LoyaltyPermissions::jobs(), ['sa'], RoleLevel::Admin)),
    'a Super Admin' => fn () => Actor::staff(Fx::staff(superAdmin: true)),
    'a job a Super Admin queued' => fn () => Actor::system(Actor::staff(Fx::staff(superAdmin: true))),
]);

it('runs the sweep through its job', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 10_000);
    CarbonImmutable::setTestNow('2027-06-02 12:00:00 UTC');

    Fx::asSystem(fn () => app()->call([new ExpirePointsJob, 'handle']));

    expect(DB::table('loyalty.entries')->where('kind', 'EXPIRED')->value('points'))->toBe(-100);
});

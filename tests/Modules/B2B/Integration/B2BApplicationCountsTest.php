<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\B2B\Public\Dto\ApplicationCountsDto;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2BApi::applicationCounts (b2b.md amendment 34): one store's applications sent and approved in a
| range, for Ops's daily report. The caller draws the range — the store's midnight in its own time
| zone, to now or to the next midnight — so every day here is drawn the way Ops will draw it, from
| the store's `timezone`. Mid-January: Riyadh is UTC+3 and Cairo UTC+2, so the two stores' days
| start an hour apart.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(B2BFixtures::uploads());
});

function applicationCountsZone(string $storeCode): string
{
    return (string) DB::table('platform.stores')->where('code', $storeCode)->value('timezone');
}

/** A moment written as the store's clock shows it. */
function applicationCountsAt(string $storeCode, string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, applicationCountsZone($storeCode));
}

/**
 * Moves an application's times, written with their offset: a DateTime binding would lose its zone.
 */
function applicationCountsMove(string $applicationId, string $storeCode, string $sentLocal, ?string $decidedLocal = null): void
{
    $times = ['submitted_at' => applicationCountsAt($storeCode, $sentLocal)->format('Y-m-d H:i:sP')];

    if ($decidedLocal !== null) {
        $times['decided_at'] = applicationCountsAt($storeCode, $decidedLocal)->format('Y-m-d H:i:sP');
    }

    DB::table('b2b.applications')->where('id', $applicationId)->update($times);
}

/** A first application of a new account, sent in that store at that local time. */
function applicationCountsSent(string $storeCode, string $sentLocal): string
{
    [, $application] = B2BFixtures::sent(B2BFixtures::companyAccount(), $storeCode);
    applicationCountsMove($application->id(), $storeCode, $sentLocal);

    return $application->id();
}

/** A first application of a new account, sent and approved in that store at those local times. */
function applicationCountsApproved(string $storeCode, string $sentLocal, string $decidedLocal): string
{
    [, $application] = B2BFixtures::approved(B2BFixtures::companyAccount(), $storeCode);
    applicationCountsMove($application->id(), $storeCode, $sentLocal, $decidedLocal);

    return $application->id();
}

/** The store's whole day, midnight to midnight in its own time zone — a past day of the report. */
function applicationCountsDay(string $storeCode, string $date): ApplicationCountsDto
{
    $midnight = applicationCountsAt($storeCode, "{$date} 00:00:00");

    return app(B2BApi::class)->applicationCounts(Fx::storeId($storeCode), $midnight, $midnight->addDay());
}

/** @return array{0: int, 1: int} */
function applicationCountsPair(ApplicationCountsDto $counts): array
{
    return [$counts->submitted, $counts->approved];
}

describe('the day', function () {
    it('counts a send on the store\'s own day, from midnight up to but not including the next', function () {
        applicationCountsSent('sa', '2026-01-14 23:59:59');
        applicationCountsSent('sa', '2026-01-15 00:00:00');
        applicationCountsSent('sa', '2026-01-15 23:59:59');
        applicationCountsSent('sa', '2026-01-16 00:00:00');

        expect(applicationCountsPair(applicationCountsDay('sa', '2026-01-14')))->toBe([1, 0])
            ->and(applicationCountsPair(applicationCountsDay('sa', '2026-01-15')))->toBe([2, 0])
            ->and(applicationCountsPair(applicationCountsDay('sa', '2026-01-16')))->toBe([1, 0]);
    });

    it('draws Egypt\'s day in Egypt\'s time zone, not Riyadh\'s', function () {
        // 23:30 in Cairo is already 00:30 the next day in Riyadh.
        applicationCountsSent('eg', '2026-01-14 23:30:00');

        expect(applicationCountsZone('eg'))->toBe('Africa/Cairo')
            ->and(applicationCountsAt('eg', '2026-01-14 23:30:00')->setTimezone('Asia/Riyadh')->format('Y-m-d'))->toBe('2026-01-15')
            ->and(applicationCountsPair(applicationCountsDay('eg', '2026-01-14')))->toBe([1, 0])
            ->and(applicationCountsPair(applicationCountsDay('eg', '2026-01-15')))->toBe([0, 0]);
    });

    it('counts today until the moment asked, as the report pressed at noon does', function () {
        applicationCountsSent('sa', '2026-01-15 10:00:00');
        applicationCountsSent('sa', '2026-01-15 14:00:00');
        applicationCountsApproved('sa', '2026-01-14 09:00:00', '2026-01-15 11:59:59');
        applicationCountsApproved('sa', '2026-01-14 09:00:00', '2026-01-15 12:00:00');

        $counts = app(B2BApi::class)->applicationCounts(
            Fx::storeId('sa'),
            applicationCountsAt('sa', '2026-01-15 00:00:00'),
            applicationCountsAt('sa', '2026-01-15 12:00:00'),
        );

        expect(applicationCountsPair($counts))->toBe([1, 1]);
    });

    it('reads the same moments the same, whatever zone the caller wrote them in', function () {
        applicationCountsSent('sa', '2026-01-15 01:00:00');
        applicationCountsApproved('sa', '2026-01-14 09:00:00', '2026-01-15 02:00:00');
        $from = applicationCountsAt('sa', '2026-01-15 00:00:00');
        $api = app(B2BApi::class);

        $riyadh = $api->applicationCounts(Fx::storeId('sa'), $from, $from->addDay());
        $utc = $api->applicationCounts(Fx::storeId('sa'), $from->setTimezone('UTC'), $from->addDay()->setTimezone('UTC'));
        $plain = $api->applicationCounts(Fx::storeId('sa'), new DateTimeImmutable('2026-01-14 21:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-01-15 21:00:00', new DateTimeZone('UTC')));

        expect(applicationCountsPair($riyadh))->toBe([1, 1])
            ->and(applicationCountsPair($utc))->toBe([1, 1])
            ->and(applicationCountsPair($plain))->toBe([1, 1]);
    });
});

describe('what counts', function () {
    it('keeps each store\'s applications to that store', function () {
        applicationCountsSent('sa', '2026-01-15 10:00:00');
        applicationCountsSent('sa', '2026-01-15 11:00:00');
        applicationCountsApproved('sa', '2026-01-15 09:00:00', '2026-01-15 12:00:00');
        applicationCountsSent('eg', '2026-01-15 10:00:00');

        expect(applicationCountsPair(applicationCountsDay('sa', '2026-01-15')))->toBe([3, 1])
            ->and(applicationCountsPair(applicationCountsDay('eg', '2026-01-15')))->toBe([1, 0]);
    });

    it('counts an approval on the day it was decided, not the day it was sent', function () {
        applicationCountsApproved('sa', '2026-01-14 10:00:00', '2026-01-15 09:00:00');

        expect(applicationCountsPair(applicationCountsDay('sa', '2026-01-14')))->toBe([1, 0])
            ->and(applicationCountsPair(applicationCountsDay('sa', '2026-01-15')))->toBe([0, 1]);
    });

    it('counts a send after a rejection again, and a rejection never as an approval', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [, $rejected] = B2BFixtures::rejected($customerId);
        applicationCountsMove($rejected->id(), 'sa', '2026-01-15 08:00:00', '2026-01-15 09:00:00');

        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['cr_number' => '1010999999']));
        app(SubmitApplicationHandler::class)->handle(new SubmitApplication);
        $again = app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('sa'));
        applicationCountsMove((string) $again?->id(), 'sa', '2026-01-15 10:00:00');

        expect($again?->id())->not->toBeNull()
            ->and($again?->id())->not->toBe($rejected->id())
            ->and(applicationCountsPair(applicationCountsDay('sa', '2026-01-15')))->toBe([2, 0]);
    });

    it('keeps an approval counted once the company is suspended, and never counts a draft', function () {
        [$company, $application] = B2BFixtures::approved(B2BFixtures::companyAccount());
        applicationCountsMove($application->id(), 'sa', '2026-01-15 08:00:00', '2026-01-15 09:00:00');
        B2BFixtures::suspend($company);
        B2BFixtures::storedDraft(B2BFixtures::companyAccount());

        expect(applicationCountsPair(applicationCountsDay('sa', '2026-01-15')))->toBe([1, 1]);
    });
});

describe('nothing to count, and a caller\'s bugs', function () {
    it('answers nought for an empty day, an empty range and a store with nothing', function () {
        applicationCountsApproved('sa', '2026-01-15 08:00:00', '2026-01-15 09:00:00');
        $moment = applicationCountsAt('sa', '2026-01-15 08:00:00');
        $api = app(B2BApi::class);

        expect(applicationCountsPair(applicationCountsDay('sa', '2026-01-20')))->toBe([0, 0])
            ->and(applicationCountsPair(applicationCountsDay('eg', '2026-01-15')))->toBe([0, 0])
            ->and(applicationCountsPair($api->applicationCounts(Fx::storeId('sa'), $moment, $moment)))->toBe([0, 0])
            ->and(applicationCountsPair($api->applicationCounts(strtolower((string) Str::ulid()), $moment->subDay(), $moment->addDay())))->toBe([0, 0])
            ->and(applicationCountsPair(applicationCountsDay('sa', '2026-01-15')))->toBe([1, 1]);
    });

    it('refuses a range that ends before it starts', function () {
        $moment = applicationCountsAt('sa', '2026-01-15 08:00:00');

        app(B2BApi::class)->applicationCounts(Fx::storeId('sa'), $moment, $moment->subSecond());
    })->throws(InvalidArgumentException::class, 'The range ends before it starts.');

    it('refuses a store id that is not a ULID', function () {
        $moment = applicationCountsAt('sa', '2026-01-15 08:00:00');

        app(B2BApi::class)->applicationCounts('sa', $moment, $moment->addDay());
    })->throws(InvalidArgumentException::class, 'expected a ULID');
});

it('reads in one query', function () {
    applicationCountsSent('sa', '2026-01-15 10:00:00');
    applicationCountsApproved('sa', '2026-01-15 09:00:00', '2026-01-15 12:00:00');
    $storeId = Fx::storeId('sa');
    $from = applicationCountsAt('sa', '2026-01-15 00:00:00');
    $api = app(B2BApi::class);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $counts = $api->applicationCounts($storeId, $from, $from->addDay());

    expect(applicationCountsPair($counts))->toBe([2, 1])
        ->and($queries)->toBe(1);
});

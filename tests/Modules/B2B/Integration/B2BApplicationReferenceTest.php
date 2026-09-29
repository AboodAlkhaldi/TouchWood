<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Application\Query\ListCompanies\ListCompanies;
use Modules\B2B\Application\Query\ListCompanies\ListCompaniesHandler;
use Modules\B2B\Application\Query\ViewCompany\ViewCompany;
use Modules\B2B\Application\Query\ViewCompany\ViewCompanyHandler;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompany;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompanyHandler;
use Modules\B2B\Domain\Exception\MissingRequiredDocument;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseApplicationReferenceCounter;
use Modules\B2B\Infrastructure\Listener\AnonymizeCompany;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| An application's number (b2b.md §1.2, §5, amendment 14(g)): given when it is sent, TW-CO-26-0001,
| the year as its home store's clock reads it, a count restarting each year across every store, and
| no gaps — a refused send gives its number back. Shown to the company and to staff, who can search
| by it.
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
    CarbonImmutable::setTestNow();
});

/**
 * A company account of that store, email confirmed, acting, with its draft filled in completely —
 * or without its papers, so sending it is refused.
 */
function applicationReferenceDraft(string $storeCode = 'sa', bool $papers = true): string
{
    $customerId = Fx::customer(strtolower((string) Str::ulid()).'@example.test', $storeCode, 'company');
    DB::table('access.customers')->where('id', $customerId)->update(['email_verified_at' => now()]);
    Fx::actAsCustomer($customerId);

    app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
    app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft([
        'name' => 'Al Noor Trading',
        'company_type_id' => B2BFixtures::companyTypes($storeCode)[0]->id(),
        'cr_number' => '1010123456',
        'tax_number' => '300123456700003',
        'address' => "King Fahd Road\nRiyadh",
    ]));

    foreach ($papers ? B2BFixtures::documentTypes($storeCode) : [] as $type) {
        if ($type->isActive()) {
            app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($type->id(), B2BFixtures::pdf(), 'paper.pdf'));
        }
    }

    return $customerId;
}

/**
 * Sends the acting account's draft, and answers the number it was given.
 */
function applicationReferenceSend(string $customerId): string
{
    app(SubmitApplicationHandler::class)->handle(new SubmitApplication);

    return (string) DB::table('b2b.applications')->where('customer_id', $customerId)->whereNotNull('reference')->orderByDesc('submitted_at')->value('reference');
}

it('numbers each application as it is sent, one after the other in the year', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 10:00', 'UTC'));

    expect(applicationReferenceSend(applicationReferenceDraft()))->toBe('TW-CO-26-0001')
        ->and(applicationReferenceSend(applicationReferenceDraft()))->toBe('TW-CO-26-0002')
        // Every store shares the one count.
        ->and(applicationReferenceSend(applicationReferenceDraft('eg')))->toBe('TW-CO-26-0003')
        ->and(DB::table('b2b.application_reference_counters')->where('year', 2026)->value('last_number'))->toBe(3);
});

it('reads the year from the home store\'s clock, and starts the new year at 0001', function () {
    // 21:30 on 31 December in UTC is half past midnight on 1 January in Riyadh, and half past
    // eleven on 31 December in Cairo.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 21:30', 'UTC'));

    $cairo = applicationReferenceSend(applicationReferenceDraft('eg'));
    $riyadh = applicationReferenceSend(applicationReferenceDraft('sa'));

    expect($cairo)->toBe('TW-CO-26-0001')
        ->and($riyadh)->toBe('TW-CO-27-0001');
});

it('gives a refused send\'s number back: nothing is skipped', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 10:00', 'UTC'));
    applicationReferenceDraft(papers: false);

    expect(fn () => app(SubmitApplicationHandler::class)->handle(new SubmitApplication))->toThrow(MissingRequiredDocument::class)
        ->and(DB::table('b2b.application_reference_counters')->count())->toBe(0)
        ->and(applicationReferenceSend(applicationReferenceDraft()))->toBe('TW-CO-26-0001');
});

it('takes the year\'s count inside the send\'s own transaction', function () {
    $customerId = applicationReferenceDraft();
    $levels = [];
    DB::listen(static function ($query) use (&$levels): void {
        if (str_contains($query->sql, 'application_reference_counters')) {
            $levels[] = DB::transactionLevel();
        }
    });

    applicationReferenceSend($customerId);

    // Level 2: the handler's transaction, inside the test's (RefreshDatabase; lesson 87).
    expect($levels)->toBe([2]);
});

it('refuses to count outside a transaction, where a failed send could not give its number back', function () {
    // RefreshDatabase keeps one open for the whole test, so the counter is given a connection that
    // has none: it must refuse before it asks the database anything.
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transactionLevel')->andReturn(0);
    $connection->shouldNotReceive('selectOne');

    app()->instance(ConnectionInterface::class, $connection);

    expect(fn () => app(DatabaseApplicationReferenceCounter::class)->next(2026))->toThrow(LogicException::class);
});

it('shows the number to the company and to staff, and staff find the company by it', function () {
    $customerId = applicationReferenceDraft();
    $reference = applicationReferenceSend($customerId);
    $companyId = (string) app(CompanyRepository::class)->forCustomer($customerId)?->id();

    $mine = app(ViewMyCompanyHandler::class)->handle(new ViewMyCompany);

    Fx::actAsAdmin(['sa'], [B2BPermissions::COMPANY_VIEW]);
    $staff = app(ViewCompanyHandler::class)->handle(new ViewCompany($companyId));
    $found = static fn (string $search): array => array_map(
        static fn ($row): string => $row->id,
        app(ListCompaniesHandler::class)->handle(new ListCompanies(search: $search))->companies,
    );

    expect($mine->history[0]->reference)->toBe($reference)
        ->and($staff->applications[0]->reference)->toBe($reference)
        ->and($found($reference))->toBe([$companyId])
        // Quoted whole, in any case, with spaces around it.
        ->and($found('  '.strtolower($reference).' '))->toBe([$companyId])
        // Part of a number is not a number.
        ->and($found(substr($reference, 0, 9)))->toBe([]);
});

it('keeps the number when the account is erased: it names nobody', function () {
    $customerId = applicationReferenceDraft();
    $reference = applicationReferenceSend($customerId);

    app(AnonymizeCompany::class)->handle(new CustomerAnonymized(strtolower((string) Str::ulid()), $customerId, CarbonImmutable::now()));

    expect(DB::table('b2b.applications')->where('customer_id', $customerId)->whereNotNull('submitted_at')->value('reference'))->toBe($reference);
});

it('numbers the applications already sent when the column is added, oldest first, in each one\'s year', function () {
    $first = B2BFixtures::sent(B2BFixtures::companyAccount())[1]->id();
    $second = B2BFixtures::sent(B2BFixtures::companyAccount())[1]->id();
    $third = B2BFixtures::sent(B2BFixtures::companyAccount())[1]->id();
    $draftAccount = B2BFixtures::companyAccount();
    B2BFixtures::storedDraft($draftAccount);

    $migration = require base_path('src/Modules/B2B/Infrastructure/Persistence/Migrations/2026_09_30_100000_add_b2b_application_references.php');
    $migration->down();

    // The second sent last, and the third across the new year in Riyadh although not in UTC.
    DB::table('b2b.applications')->where('id', $first)->update(['submitted_at' => '2026-03-01 09:00:00+00']);
    DB::table('b2b.applications')->where('id', $second)->update(['submitted_at' => '2026-11-01 09:00:00+00']);
    DB::table('b2b.applications')->where('id', $third)->update(['submitted_at' => '2026-12-31 21:30:00+00']);

    $migration->up();

    expect(DB::table('b2b.applications')->whereIn('id', [$first, $second, $third])->orderBy('submitted_at')->pluck('reference')->all())
        ->toBe(['TW-CO-26-0001', 'TW-CO-26-0002', 'TW-CO-27-0001'])
        ->and(DB::table('b2b.applications')->where('customer_id', $draftAccount)->value('reference'))->toBeNull()
        ->and(DB::table('b2b.application_reference_counters')->orderBy('year')->get()->map(fn ($row): array => [(int) $row->year, (int) $row->last_number])->all())
        ->toBe([[2026, 2], [2027, 1]]);
});

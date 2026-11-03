<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccountType;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\CountStoreAudience\CountStoreAudience;
use Modules\Ops\Application\Query\CountStoreAudience\CountStoreAudienceHandler;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

/*
| A whole store's audience (ops.md §4.1; owner, 2026-11-02, §9.4 Q14): the number the confirmation names before a
| notice or a message goes to every customer of a store. It can't disagree with what is sent: these tests send, and
| compare.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CapturedEmails::install();
});

const AUDIENCE_JSON = ['HTTP_ACCEPT' => 'application/json'];

function audienceStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * The store's customers of every sort the sender treats apart: one who may receive offers by email, one who never
 * agreed, one blocked, one awaiting deletion, a company - five the bell reaches - and those it does not: one
 * anonymized, one of another store.
 */
function audienceCustomers(): void
{
    Wx::agreeing('agrees@example.test');
    Fx::customer('never-agreed@example.test');
    Wx::set(Wx::agreeing('blocked@example.test'), ['status' => 'BLOCKED']);
    Wx::set(Wx::agreeing('leaving@example.test'), ['deletion_scheduled_for' => now()->addDays(10)->toIso8601String()]);
    Fx::customer('firm@example.test', 'sa', 'company');
    Wx::set(Wx::agreeing('gone@example.test'), ['anonymized_at' => now()->toIso8601String()]);
    Wx::agreeing('elsewhere@example.test', 'eg');
}

describe('the count against what is sent', function () {
    it('names exactly who a message to every customer reaches by bell; its email goes to fewer', function () {
        audienceCustomers();
        $browser = audienceStaff(Fx::staffWith([OpsPermissions::MESSAGE_SEND_MANY], ['sa'], RoleLevel::Admin));

        $browser->get('/admin/ops/audience?store=sa', AUDIENCE_JSON)->assertOk()->assertExactJson(['count' => 5]);

        $id = $browser->post('/admin/messages', [
            'store' => 'sa', 'audience' => 'STORE', 'title_ar' => 'ع', 'title_en' => 'Hello', 'body_ar' => 'ن', 'body_en' => 'Text', 'by_bell' => '1', 'by_email' => '1',
        ])->assertCreated()->json('id');
        $sent = DB::table('ops.staff_messages')->where('id', $id)->first();

        expect($sent?->status)->toBe('SENT')
            ->and($sent?->bell_count)->toBe(5)
            ->and(DB::table('ops.inbox_entries')->where('store_id', Fx::storeId('sa'))->whereNotNull('customer_id')->distinct()->count('customer_id'))->toBe(5)
            // Consent decides the email, never the count (§1.3): only the one who agreed, not blocked or leaving.
            ->and($sent?->email_count)->toBe(1);
    });

    it('names exactly who a notice sent now reaches by bell', function () {
        audienceCustomers();
        $browser = audienceStaff(Fx::staffWith([OpsPermissions::NOTICE_MANAGE], ['sa']));

        $browser->get('/admin/ops/audience?store=sa', AUDIENCE_JSON)->assertOk()->assertExactJson(['count' => 5]);

        $id = $browser->post('/admin/notices', [
            'store' => 'sa', 'title_ar' => 'عرض', 'title_en' => 'Offer', 'body_ar' => 'نص', 'body_en' => 'Text', 'by_bell' => '1', 'by_email' => '1',
        ])->assertCreated()->json('id');
        $sent = DB::table('ops.notices')->where('id', $id)->first();

        expect($sent?->status)->toBe('SENT')
            ->and($sent?->bell_count)->toBe(5)
            ->and($sent?->email_count)->toBe(1);
    });

    it('is the same people as the pages the sender walks, both account types', function () {
        audienceCustomers();
        $access = app(AccessApi::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));
        $walked = [...$access->customerIdsInStore($sa, AccountType::Individual, null, 1000), ...$access->customerIdsInStore($sa, AccountType::Company, null, 1000)];

        Fx::actAsStaff(Fx::staffWith([OpsPermissions::NOTICE_MANAGE], ['sa']));

        expect(app(CountStoreAudienceHandler::class)->handle(new CountStoreAudience(Fx::storeId('sa'))))->toBe(count($walked))
            ->and(count($walked))->toBe(5);
    });

    it('answers 0 for a store with no customers', function () {
        Wx::agreeing('elsewhere@example.test', 'eg');
        $browser = audienceStaff(Fx::staffWith([OpsPermissions::NOTICE_MANAGE], ['sa']));

        $browser->get('/admin/ops/audience?store=sa', AUDIENCE_JSON)->assertOk()->assertExactJson(['count' => 0]);
    });

    it('takes the first store on offer when none is named, as its sibling reads do', function () {
        Fx::customer('omar@example.test', 'eg');
        $browser = audienceStaff(Fx::staffWith([OpsPermissions::NOTICE_MANAGE], ['eg']));

        $browser->get('/admin/ops/audience', AUDIENCE_JSON)->assertOk()->assertExactJson(['count' => 1]);
    });
});

describe('who may read it', function () {
    it('answers either form\'s permission in the store', function (string $permission) {
        $browser = audienceStaff(Fx::staffWith([$permission], ['sa'], RoleLevel::Admin));

        $browser->get('/admin/ops/audience?store=sa', AUDIENCE_JSON)->assertOk();
    })->with([OpsPermissions::NOTICE_MANAGE, OpsPermissions::MESSAGE_SEND_MANY]);

    it('refuses anyone else, as its sibling reads do', function (string $permission, string $store) {
        $browser = audienceStaff(Fx::staffWith([$permission], ['sa'], RoleLevel::Admin));

        $browser->get("/admin/ops/audience?store={$store}", AUDIENCE_JSON)->assertForbidden()->assertJsonPath('type', 'shared.unauthorized');
    })->with([
        'a message to one customer alone' => [OpsPermissions::MESSAGE_SEND_ONE, 'sa'],
        'the reports alone' => [OpsPermissions::REPORT_VIEW, 'sa'],
        'a notice in another store' => [OpsPermissions::NOTICE_MANAGE, 'eg'],
        'a message to many in another store' => [OpsPermissions::MESSAGE_SEND_MANY, 'eg'],
        'a store that is not one' => [OpsPermissions::NOTICE_MANAGE, 'zz'],
    ]);

    it('refuses anyone signed out', function () {
        getJson('/admin/ops/audience?store=sa')->assertUnauthorized();
    });

    it('checks the permission in the handler too: offering is not allowing', function () {
        Fx::actAsStaff(Fx::staffWith([OpsPermissions::NOTICE_MANAGE], ['sa']));

        expect(fn () => app(CountStoreAudienceHandler::class)->handle(new CountStoreAudience(Fx::storeId('eg'))))->toThrow(Unauthorized::class);
    });
});

it('reads one count on the customers, in the same number of queries however many they are', function () {
    config(['session.lottery' => [0, 100]]);
    Fx::customer('first@example.test');
    $browser = audienceStaff(Fx::staffWith([OpsPermissions::NOTICE_MANAGE], ['sa']));
    $read = static function () use ($browser): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $browser->get('/admin/ops/audience?store=sa', AUDIENCE_JSON)->assertOk();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    };
    // Warm the caches (the stores, the permissions), as a second request finds them.
    $read();
    $few = $read();

    // Written straight in the table: registering one here would sign this browser out.
    $row = (array) DB::table('access.customers')->first();
    foreach (range(1, 4) as $more) {
        DB::table('access.customers')->insert([...$row, 'id' => strtolower((string) Str::ulid()), 'email' => "more{$more}@example.test"]);
    }
    $many = $read();
    $counts = array_values(array_filter($many, static fn (string $query): bool => str_contains($query, '"access"."customers"')));

    expect(count($many))->toBe(count($few))
        ->and($counts)->toBe(['select count(*) as "aggregate" from "access"."customers" where "home_store_id" = ? and "anonymized_at" is null']);
});

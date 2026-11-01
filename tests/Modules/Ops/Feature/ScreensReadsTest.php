<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\NewsletterUnavailable;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\CapturedNewsletterList;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| What the screens agent reads (ops.md §4, §4.13; owner, 2026-11-01): the staff messages list, the stores and
| report kinds each form may offer, the notice's other facts, the composer's result, the words of the
| confirmation page; and how a notice or subscriber of another store, and a provider's failure, are answered.
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
    CapturedNewsletterList::install();
});

const SCREENS_JSON = ['HTTP_ACCEPT' => 'application/json'];

function screensStaff(string $staffId): AdminBrowser
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
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function screensMessage(array $extra = []): array
{
    return ['store' => 'sa', 'title_ar' => 'ع', 'title_en' => 'Hello', 'body_ar' => 'ن', 'body_en' => 'Text', 'by_bell' => '1', ...$extra];
}

/** A notice written straight in its table: acting as someone here would be who every request is. */
function screensNotice(string $storeCode, string $writer, string $status = 'SENT'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.notices')->insert([
        'id' => $id, 'store_id' => Fx::storeId($storeCode), 'title_ar' => 'ع', 'title_en' => 'T', 'body_ar' => 'ن', 'body_en' => 'B', 'by_bell' => true, 'by_email' => false,
        'status' => $status, 'send_at' => now(), 'created_by' => $writer, 'created_at' => now(), 'updated_at' => now(),
        'started_at' => $status === 'SENT' ? now() : null, 'finished_at' => $status === 'SENT' ? now() : null,
    ]);

    return $id;
}

function screensGuest(string $storeCode): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.subscribers')->insert([
        'id' => $id, 'store_id' => Fx::storeId($storeCode), 'email' => 'guest-'.$storeCode.'@example.test', 'locale' => 'en', 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
    ]);

    return $id;
}

describe('the staff messages list', function () {
    it('reads back who sent what to whom, newest first, paged', function () {
        $omar = Fx::customer('omar@example.test');
        $staff = Fx::staffWith(['ops.message.send_one', 'ops.message.send_many', 'access.customer.view'], ['sa'], RoleLevel::Admin);
        $browser = screensStaff($staff);

        $one = $browser->post('/admin/messages', screensMessage(['audience' => 'ONE', 'customer' => $omar]))->assertCreated()->json('id');
        $many = $browser->post('/admin/messages', screensMessage(['audience' => 'STORE']))->assertCreated()->json('id');

        $page = $browser->get('/admin/messages?store=sa', SCREENS_JSON)->assertOk()->json();

        expect(array_column($page['messages'], 'id'))->toEqualCanonicalizing([$one, $many])
            ->and($page['next'])->toBeNull();
        /** @var list<array<string, mixed>> $messages */
        $messages = $page['messages'];
        /** @var array<string, mixed> $row */
        $row = collect($messages)->firstWhere('id', $one);
        expect($row)->toMatchArray(['storeCode' => 'sa', 'audience' => 'ONE', 'customerId' => $omar, 'customerName' => 'Sara Ali', 'segmentId' => null, 'titleEn' => 'Hello', 'byBell' => true, 'byEmail' => false, 'status' => 'SENT', 'bellCount' => 1, 'emailCount' => 0, 'failedCount' => 0])
            ->and($row['sentBy'])->not->toBe('')
            ->and($row['createdAt'])->toEndWith('+00:00')
            ->and($row['finishedAt'])->toEndWith('+00:00');
        expect(collect($messages)->firstWhere('id', $many))->toMatchArray(['audience' => 'STORE', 'customerId' => null, 'customerName' => null]);
    });

    it('pages by the cursor it gave, and refuses one that is no id', function () {
        $omar = Fx::customer('omar@example.test');
        $staff = Fx::staffWith(['ops.message.send_many'], ['sa'], RoleLevel::Admin);
        $base = ['store_id' => Fx::storeId('sa'), 'sender_id' => $staff, 'audience' => 'STORE', 'title_ar' => 'ع', 'title_en' => 'T', 'body_ar' => 'ن', 'body_en' => 'B', 'by_bell' => true, 'by_email' => false, 'status' => 'SENT', 'finished_at' => now()];

        for ($i = 0; $i < 21; $i++) {
            DB::table('ops.staff_messages')->insert([...$base, 'id' => strtolower((string) Str::ulid()), 'created_at' => now()->subMinutes($i)]);
        }

        $browser = screensStaff($staff);
        $first = $browser->get('/admin/messages?store=sa', SCREENS_JSON)->assertOk()->json();
        $second = $browser->get('/admin/messages?store=sa&after='.$first['next'], SCREENS_JSON)->assertOk()->json();

        expect($first['messages'])->toHaveCount(20)
            ->and($first['next'])->not->toBeNull()
            ->and($second['messages'])->toHaveCount(1)
            ->and($second['next'])->toBeNull()
            ->and($browser->get('/admin/messages?store=sa&after=nope', SCREENS_JSON)->assertUnprocessable()->json('type'))->toBe('ops.invalid_ops_input');
    });

    it('shows each what they may send: send_many the messages to many; send_one those to one, only while viewing customers', function () {
        $omar = Fx::customer('omar@example.test');
        $writer = Fx::staffWith(['ops.message.send_one', 'ops.message.send_many', 'access.customer.view'], ['sa'], RoleLevel::Admin);
        $browser = screensStaff($writer);
        $browser->post('/admin/messages', screensMessage(['audience' => 'ONE', 'customer' => $omar]))->assertCreated();
        $browser->post('/admin/messages', screensMessage(['audience' => 'STORE']))->assertCreated();

        $audiences = static fn (AdminBrowser $reader): array => array_column($reader->get('/admin/messages?store=sa', SCREENS_JSON)->assertOk()->json('messages'), 'audience');

        expect($audiences(screensStaff(Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa']))))->toBe(['ONE'])
            ->and($audiences(screensStaff(Fx::staffWith(['ops.message.send_one'], ['sa']))))->toBe([])
            ->and($audiences(screensStaff(Fx::staffWith(['ops.message.send_many'], ['sa'], RoleLevel::Admin))))->toBe(['STORE']);

        // Neither held in the store, or in the store asked: refused.
        screensStaff(Fx::staffWith(['ops.report.view'], ['sa']))->get('/admin/messages?store=sa', SCREENS_JSON)->assertForbidden();
        screensStaff(Fx::staffWith(['ops.message.send_one'], ['ae']))->get('/admin/messages?store=sa', SCREENS_JSON)->assertForbidden();
        getJson('/admin/messages?store=sa')->assertUnauthorized();
    });
});

describe('the choices', function () {
    it('offers each form the stores of its own permission, and only the reports the reader may open', function () {
        $browser = screensStaff(Fx::staffWith(['ops.notice.manage', 'ops.report.view'], ['sa']));

        $choices = $browser->get('/admin/ops/choices', SCREENS_JSON)->assertOk()->json();

        expect(array_column($choices['notices'], 'code'))->toBe(['sa'])
            ->and($choices['notices'][0])->toBe(['id' => Fx::storeId('sa'), 'code' => 'sa', 'name' => 'Saudi Arabia', 'isOn' => true])
            ->and($choices['messagesToOne'])->toBe([])
            ->and($choices['messagesToMany'])->toBe([])
            ->and($choices['subscribers'])->toBe([])
            ->and($choices['dailyReports'])->toBe([])
            ->and($choices['dailyReportsAllStores'])->toBeFalse()
            ->and(array_column($choices['reports'], 'kind'))->toBe(['revenue', 'product-sales', 'searches', 'favourites'])
            ->and($choices['reports'][0]['allStores'])->toBeFalse()
            ->and(array_column($choices['reports'][0]['stores'], 'code'))->toBe(['sa']);
    });

    it('offers Stock only where the reader also holds inventory.stock.manage', function () {
        $browser = screensStaff(Fx::staffWith(['ops.report.view', 'inventory.stock.manage'], ['sa'], exceptions: []));
        /** @var list<array{kind: string, stores: list<array<string, mixed>>}> $kinds */
        $kinds = $browser->get('/admin/ops/choices', SCREENS_JSON)->assertOk()->json('reports');

        expect(array_column($kinds, 'kind'))->toContain('stock')
            ->and(array_column(collect($kinds)->firstWhere('kind', 'stock')['stores'] ?? [], 'code'))->toBe(['sa']);

        $elsewhere = screensStaff(Fx::staffWith(['inventory.stock.manage'], ['ae']));
        expect($elsewhere->get('/admin/ops/choices', SCREENS_JSON)->assertOk()->json('reports'))->toBe([]);
    });

    it('offers a Super Admin every store, the off ones marked, and every store\'s reports', function () {
        DB::table('platform.stores')->where('code', 'ae')->update(['is_active' => false]);
        cache()->flush();

        $choices = screensStaff(Fx::staff(superAdmin: true))->get('/admin/ops/choices', SCREENS_JSON)->assertOk()->json();

        expect(array_column($choices['notices'], 'isOn', 'code')['ae'])->toBeFalse()
            ->and($choices['dailyReportsAllStores'])->toBeTrue()
            ->and(array_column($choices['reports'], 'kind'))->toBe(['revenue', 'product-sales', 'stock', 'searches', 'favourites'])
            ->and($choices['reports'][0]['allStores'])->toBeTrue();
    });

    it('is refused to anyone signed out', function () {
        getJson('/admin/ops/choices')->assertUnauthorized();
    });
});

it('gives a notice its writer, its moments and no invented size', function () {
    $staff = Fx::staffWith(['ops.notice.manage'], ['sa']);
    $notice = screensNotice('sa', $staff);

    $found = screensStaff($staff)->get("/admin/notices/{$notice}", SCREENS_JSON)->assertOk()->json();

    expect($found['writtenBy'])->not->toBe('')
        ->and($found['startedAt'])->toEndWith('+00:00')
        ->and($found['finishedAt'])->toEndWith('+00:00')
        ->and($found)->not->toHaveKey('audienceSize');
});

it('offers in the composer only the chosen store\'s customers, with their phone and home store', function () {
    $here = Fx::customer('here@example.test', 'sa');
    DB::table('access.customers')->where('id', $here)->update(['phone' => '+966500000077', 'phone_verified_at' => now()]);
    cache()->flush();
    Fx::customer('there@example.test', 'ae');
    $browser = screensStaff(Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa', 'ae']));

    $found = $browser->get('/admin/messages/customers?store=sa&search=Sara', SCREENS_JSON)->assertOk()->json('customers');

    expect($found)->toBe([['id' => $here, 'firstName' => 'Sara', 'lastName' => 'Ali', 'email' => 'here@example.test', 'phone' => '+966500000077', 'homeStoreCode' => 'sa']]);
});

it('words the confirmation page in the page\'s language, and has the box\'s words in both', function () {
    getJson('/sa/ar/newsletter/confirm/abc123')->assertOk()->assertJson(['token' => 'abc123', 'action' => 'تأكيد']);
    getJson('/sa/en/newsletter/confirm/abc123')->assertOk()->assertJson(['token' => 'abc123', 'action' => 'Confirm', 'title' => 'Confirm Your Subscription']);

    foreach (['title', 'text', 'email', 'action', 'sent'] as $word) {
        expect(trans("ops::newsletter.box.{$word}", [], 'ar'))->not->toBe("ops::newsletter.box.{$word}")
            ->and(trans("ops::newsletter.box.{$word}", [], 'en'))->not->toBe("ops::newsletter.box.{$word}");
    }
});

describe('a row of another store (as the sibling modules: not found)', function () {
    it('answers a notice of a store the reader does not manage as unknown, and someone managing none is refused', function () {
        $notice = screensNotice('ae', Fx::staff(), 'SCHEDULED');
        $elsewhere = screensStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));

        expect($elsewhere->get("/admin/notices/{$notice}", SCREENS_JSON)->assertNotFound()->json('type'))->toBe('ops.notice_not_found');
        $elsewhere->post("/admin/notices/{$notice}/cancel")->assertNotFound();
        $elsewhere->post("/admin/notices/{$notice}", screensMessage())->assertNotFound();

        $none = screensStaff(Fx::staffWith(['ops.report.view'], ['ae']));
        $none->get("/admin/notices/{$notice}", SCREENS_JSON)->assertForbidden();
        $none->post("/admin/notices/{$notice}/cancel")->assertForbidden();

        screensStaff(Fx::staffWith(['ops.notice.manage'], ['ae']))->get("/admin/notices/{$notice}", SCREENS_JSON)->assertOk();
    });

    it('answers a guest of a store the reader does not manage as unknown, and someone managing none is refused', function () {
        $guest = screensGuest('ae');

        screensStaff(Fx::staffWith(['ops.newsletter.manage'], ['sa']))->post("/admin/newsletter/subscribers/{$guest}/remove")->assertNotFound();
        screensStaff(Fx::staffWith(['ops.report.view'], ['ae']))->post("/admin/newsletter/subscribers/{$guest}/remove")->assertForbidden();

        expect(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeTrue();

        screensStaff(Fx::staffWith(['ops.newsletter.manage'], ['ae']))->post("/admin/newsletter/subscribers/{$guest}/remove")->assertNoContent();
        expect(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeFalse();
    });
});

describe('a refused value on a form', function () {
    it('names the field in words through FormErrors, unworded in the problem document', function () {
        app()->setLocale('en');

        expect(FormErrors::message(new InvalidOpsInput('title_ar', 'empty')))->toBe('The Arabic title isn\'t valid. Check it and try again.');
    });
});

describe('the newsletter box when the provider fails', function () {
    it('answers its own error, 503, in the page\'s language, reports the failure, and keeps the sign-up', function () {
        Exceptions::fake();
        app()->instance(EmailSender::class, new class implements EmailSender
        {
            public function send(OutgoingEmail $email): ?string
            {
                throw new ProviderFailed('Brevo refused the email: HTTP 500.');
            }
        });

        $en = postJson('/sa/en/newsletter', ['email' => 'guest@example.test'])->assertStatus(503);
        $ar = postJson('/sa/ar/newsletter', ['email' => 'other@example.test'])->assertStatus(503);

        Exceptions::assertReported(ProviderFailed::class);
        Exceptions::assertNotReported(NewsletterUnavailable::class);

        expect($en->json('type'))->toBe('ops.newsletter_unavailable')
            ->and($en->json('title'))->toBe('Message Not Sent')
            ->and($en->json('detail'))->toBe('We couldn\'t send the message just now. Try again in a few minutes.')
            ->and($ar->json('title'))->toBe('لم تُرسل الرسالة')
            ->and((string) $en->getContent())->not->toContain('Brevo')
            ->and(DB::table('ops.subscribers')->where('email', 'guest@example.test')->exists())->toBeTrue()
            ->and(DB::table('ops.sent_messages')->where('kind', 'newsletter_confirmation')->pluck('status')->unique()->all())->toBe(['FAILED']);
    });
});

it('reads each new list in the same number of queries however many rows it shows', function () {
    config(['session.lottery' => [0, 100]]);
    $omar = Fx::customer('omar@example.test');
    $staff = Fx::staffWith(['ops.message.send_one', 'ops.message.send_many', 'ops.notice.manage', 'access.customer.view'], ['sa'], RoleLevel::Admin);
    $browser = screensStaff($staff);
    $count = static function (string $address) use ($browser): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $browser->get($address, SCREENS_JSON)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $rows = static function (int $many) use ($omar, $staff): void {
        for ($i = 0; $i < $many; $i++) {
            screensNotice('sa', $staff);
            DB::table('ops.staff_messages')->insert(['id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'sender_id' => $staff, 'audience' => 'ONE', 'customer_id' => $omar, 'title_ar' => 'ع', 'title_en' => 'T', 'body_ar' => 'ن', 'body_en' => 'B', 'by_bell' => true, 'by_email' => false, 'status' => 'SENT', 'created_at' => now(), 'finished_at' => now()]);
        }
    };
    $rows(1);
    // Warm the caches (the stores, the permissions), as a second request finds them.
    $count('/admin/messages?store=sa');
    $count('/admin/notices?store=sa');
    $count('/admin/ops/choices');
    $few = [$count('/admin/messages?store=sa'), $count('/admin/notices?store=sa'), $count('/admin/ops/choices')];

    $rows(5);
    $more = [$count('/admin/messages?store=sa'), $count('/admin/notices?store=sa'), $count('/admin/ops/choices')];

    expect($more)->toBe($few);
    // Measured 2026-11-01, with the caches warm, the session and the staff member's sign-in included: the
    // staff messages list 12, the notices 11, the choices 10. Targets, not caps (owner, 2026-10-19).
    expect($few)->toBe([12, 11, 10]);
});

it('takes the audience outright on the send route: an empty segment or none said is refused, nothing sent', function () {
    $omar = Fx::customer('omar@example.test');
    $browser = screensStaff(Fx::staffWith(['ops.message.send_one', 'ops.message.send_many', 'access.customer.view'], ['sa'], RoleLevel::Admin));

    $browser->post('/admin/messages', screensMessage())->assertUnprocessable();
    $browser->post('/admin/messages', screensMessage(['audience' => 'SEGMENT', 'segment' => '']))->assertUnprocessable();
    $browser->post('/admin/messages', screensMessage(['audience' => 'STORE', 'customer' => $omar]))->assertUnprocessable();
    $browser->post('/admin/messages', screensMessage(['audience' => 'ONE']))->assertUnprocessable();

    expect(DB::table('ops.staff_messages')->count())->toBe(0);

    // Sending to one under send_one alone; a store under it is refused on the permission.
    $one = screensStaff(Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa']));
    $one->post('/admin/messages', screensMessage(['audience' => 'ONE', 'customer' => $omar]))->assertCreated();
    $one->post('/admin/messages', screensMessage(['audience' => 'STORE']))->assertForbidden();
});

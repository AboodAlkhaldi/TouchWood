<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\CapturedNewsletterList;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| Step 7 over HTTP (ops.md §1.7-§1.9, §4): the panel's notices, composer and subscribers as JSON, the
| shop's newsletter box and its links, a marketing email's unsubscribe link, and Brevo's webhook.
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

function writtenRoutesStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.6.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** The path of the confirmation link in the last email. */
function writtenRoutesConfirmPath(): string
{
    preg_match('#(/sa/en/newsletter/confirm/[A-Za-z0-9]+)#', CapturedEmails::installed()->last()->text, $match);

    return $match[1] ?? throw new LogicException('No confirmation link.');
}

it('writes, lists, changes and cancels a notice in the panel, as JSON', function () {
    Wx::agreeing('sara@example.test');
    $browser = writtenRoutesStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));
    $at = CarbonImmutable::now('Asia/Riyadh')->addDay()->setTime(10, 0)->format('Y-m-d H:i');

    $made = $browser->post('/admin/notices', [
        'store' => 'sa', 'title_ar' => 'عرض', 'title_en' => 'Offer', 'body_ar' => 'نص', 'body_en' => 'Text',
        'link' => 'categories/chairs', 'by_bell' => '1', 'by_email' => '1', 'send_at' => $at,
    ])->assertCreated()->json();

    expect($made)->toMatchArray(['storeCode' => 'sa', 'status' => 'SCHEDULED', 'sendAtLocal' => $at, 'changeable' => true, 'link' => 'categories/chairs', 'bellCount' => 0]);

    $list = $browser->get('/admin/notices?store=sa', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
    expect(array_column($list['notices'], 'id'))->toBe([$made['id']])->and($list['next'])->toBeNull();

    $browser->post("/admin/notices/{$made['id']}", [
        'title_ar' => 'عرض', 'title_en' => 'Better offer', 'body_ar' => 'نص', 'body_en' => 'Text', 'by_bell' => '1', 'send_at' => $at,
    ])->assertOk()->assertJsonPath('titleEn', 'Better offer')->assertJsonPath('byEmail', false);

    $browser->post("/admin/notices/{$made['id']}/cancel", [])->assertOk()->assertJsonPath('status', 'CANCELLED');
    $browser->post("/admin/notices/{$made['id']}/cancel", [])->assertStatus(409);
});

it('refuses the notices to staff without ops.notice.manage', function () {
    $browser = writtenRoutesStaff(Fx::staffWith(['ops.report.view'], ['sa']));

    $browser->get('/admin/notices?store=sa', ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();
});

it('finds a customer and sends them a message from the composer', function () {
    $omar = Fx::customer('omar@example.test');
    $browser = writtenRoutesStaff(Fx::staffWith(['ops.message.send_one'], ['sa']));

    $found = $browser->get('/admin/messages/customers?store=sa&search=Sara', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json('customers');
    expect(array_column($found, 'id'))->toBe([$omar]);

    $browser->post('/admin/messages', [
        'store' => 'sa', 'customer' => $omar, 'title_ar' => 'ع', 'title_en' => 'Hello', 'body_ar' => 'ن', 'body_en' => 'Text', 'by_bell' => '1', 'by_email' => '1',
    ])->assertCreated();
    // To many is send_many's.
    $browser->post('/admin/messages', [
        'store' => 'sa', 'title_ar' => 'ع', 'title_en' => 'Hello', 'body_ar' => 'ن', 'body_en' => 'Text', 'by_bell' => '1',
    ])->assertForbidden();

    expect(CapturedEmails::installed()->to())->toBe(['omar@example.test']);
});

it('signs a guest up from the shop\'s box and confirms them by POST, never by opening the link', function () {
    postJson('/sa/en/newsletter', ['email' => 'guest@example.test'])->assertStatus(202)->assertExactJson(['sent' => true]);

    $link = writtenRoutesConfirmPath();
    expect(CapturedEmails::installed()->last()->text)->toContain(rtrim((string) config('app.url'), '/').$link);

    get($link, ['Accept' => 'application/json'])->assertOk();
    expect(DB::table('ops.subscribers')->value('confirmed_at'))->toBeNull();

    postJson($link)->assertOk()->assertExactJson(['confirmed' => true]);
    postJson($link)->assertNotFound();
    expect(DB::table('ops.subscribers')->value('confirmed_at'))->not->toBeNull();
});

it('tells a guest whose email belongs to an account, or a signed-in customer, to use the account instead', function () {
    Fx::customer('sara@example.test');

    postJson('/sa/en/newsletter', ['email' => 'Sara@Example.test'])->assertStatus(409)->assertJsonPath('type', 'ops.subscribe_in_your_account');
    expect(CapturedEmails::installed()->sent)->toBe([])
        ->and(DB::table('ops.subscribers')->count())->toBe(0);
});

it('takes a guest off from the confirmation email\'s signed link; an unsigned one does nothing', function () {
    postJson('/sa/en/newsletter', ['email' => 'guest@example.test'])->assertStatus(202);
    postJson(writtenRoutesConfirmPath())->assertOk();
    preg_match('#(/sa/en/newsletter/leave/[0-9a-z]{26})(\?signature=[0-9a-f]+)#', CapturedEmails::installed()->last()->text, $match);
    $path = $match[1] ?? throw new LogicException('No leave link.');
    $signature = $match[2];

    get($path, ['Accept' => 'application/json'])->assertForbidden();
    expect(DB::table('ops.subscribers')->count())->toBe(1);

    get($path.$signature, ['Accept' => 'application/json'])->assertOk()->assertExactJson(['unsubscribed' => true]);
    expect(DB::table('ops.subscribers')->count())->toBe(0)
        ->and(CapturedNewsletterList::installed()->last())->toBe('remove guest@example.test '.Fx::storeId('sa').' en');
    // Followed again: nothing left to take off.
    get($path.$signature, ['Accept' => 'application/json'])->assertOk();
});

it('limits the box to 5 sign-ups an hour for one email', function () {
    foreach (range(1, 5) as $ignored) {
        postJson('/sa/en/newsletter', ['email' => 'guest@example.test'])->assertStatus(202);
    }

    postJson('/sa/en/newsletter', ['email' => 'guest@example.test'])->assertStatus(429);
});

it('switches the consent off from a marketing email\'s signed link, with no sign-in; an unsigned link does nothing', function () {
    $sara = Wx::agreeing('sara@example.test');
    event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));

    get("/sa/en/unsubscribe/{$sara}", ['Accept' => 'application/json'])->assertForbidden();
    expect(DB::table('access.customers')->where('id', $sara)->value('marketing_consent'))->toBeTrue();

    get(URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'sa', 'locale' => 'en', 'customer' => $sara]), ['Accept' => 'application/json'])->assertOk();
    expect(DB::table('access.customers')->where('id', $sara)->first())->toMatchArray(['marketing_consent' => false, 'marketing_consent_source' => 'UNSUBSCRIBE_LINK'])
        ->and(DB::table('ops.subscribers')->count())->toBe(0);
});

describe('Brevo\'s webhook', function () {
    beforeEach(function () {
        config(['services.brevo.webhook_secret' => 'webhook-secret']);
    });

    it('is refused without the secret, or with no secret set', function () {
        $body = ['event' => 'unsubscribe', 'email' => 'guest@example.test'];

        call('POST', '/newsletter/brevo', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body))->assertUnauthorized();
        call('POST', '/newsletter/brevo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TOUCHWOOD_SECRET' => 'wrong'], (string) json_encode($body))->assertUnauthorized();

        config(['services.brevo.webhook_secret' => '']);
        call('POST', '/newsletter/brevo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TOUCHWOOD_SECRET' => ''], (string) json_encode($body))->assertUnauthorized();
    });

    it('answers any other event and leaves it', function () {
        $sara = Wx::agreeing('sara@example.test');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TOUCHWOOD_SECRET' => 'webhook-secret'];

        call('POST', '/newsletter/brevo', [], [], [], $headers, (string) json_encode(['event' => 'opened', 'email' => 'sara@example.test']))->assertNoContent();
        expect(DB::table('ops.subscribers')->count())->toBe(1);
    });

    it('takes a guest off every list on an unsubscribe', function () {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TOUCHWOOD_SECRET' => 'webhook-secret'];
        postJson('/sa/en/newsletter', ['email' => 'guest@example.test'])->assertStatus(202);
        postJson(writtenRoutesConfirmPath())->assertOk();

        call('POST', '/newsletter/brevo', [], [], [], $headers, (string) json_encode(['id' => 1, 'event' => 'unsubscribe', 'email' => 'Guest@Example.test', 'list_id' => [41]]))->assertNoContent();
        expect(DB::table('ops.subscribers')->count())->toBe(0);
    });

    it('withdraws a customer\'s consent on an unsubscribe', function () {
        $sara = Wx::agreeing('sara@example.test');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TOUCHWOOD_SECRET' => 'webhook-secret'];

        call('POST', '/newsletter/brevo', [], [], [], $headers, (string) json_encode(['id' => 1, 'event' => 'unsubscribe', 'email' => 'Sara@Example.test', 'list_id' => [41]]))->assertNoContent();
        expect(DB::table('ops.subscribers')->count())->toBe(0)
            ->and(DB::table('access.customers')->where('id', $sara)->value('marketing_consent_source'))->toBe('NEWSLETTER_PROVIDER');
    });
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Ops\Application\Command\PruneOpsLog\PruneOpsLog;
use Modules\Ops\Application\Command\PruneOpsLog\PruneOpsLogHandler;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Domain\Exception\CodesUnavailable;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\AutoReplySender;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Infrastructure\Messages\WhatsAppAutoReply;
use Modules\Ops\Infrastructure\Messages\WhatsAppBanner;
use Modules\Ops\Infrastructure\Queue\HandleWhatsAppMessageJob;
use Modules\Ops\Infrastructure\Queue\HandleWhatsAppStatusJob;
use Modules\Ops\Presentation\Http\Controller\WhatsAppWebhookController;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Payments\Public\Events\GatewayKeyExpiring;
use Modules\Payments\Public\Events\GatewayKeyRejected;
use Modules\Platform\Public\Contracts\ReservedPaths;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\CapturedEmails;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

/*
| WhatsApp replaces SMS for codes (ops.md §9.8; access.md amendment 98). No real message is ever sent: Meta is faked
| over HTTP, on a frozen clock. Codes as the authentication template, in each language and purpose, with the code
| twice; Meta refusing the setup or one person; the webhook (handshake, signature, our number, size, rate); a later
| failed status turning a row SENT to FAILED and nothing else; the auto-reply (one a day, none to a status or our own
| number, the hour's cap); a missing setting (W3); the two critical alerts as templates; the table's rules.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

uses(RefreshDatabase::class);

const WHATSAPP_SECRET = 'app-secret-for-tests';
const WHATSAPP_NUMBER_ID = '105500000000001';
const WHATSAPP_SEND_URL = 'https://graph.facebook.com/v26.0/105500000000001/messages';

beforeEach(function () {
    seed(PlatformSeeder::class);
    CarbonImmutable::setTestNow('2026-11-22 10:00:00');
    config(['services.whatsapp' => [
        'graph_version' => 'v26.0',
        'graph_url' => 'https://graph.facebook.com',
        'phone_number_id' => WHATSAPP_NUMBER_ID,
        'access_token' => 'token-for-tests',
        'code_template' => 'touchwood_code',
        'app_secret' => WHATSAPP_SECRET,
        'verify_token' => 'verify-for-tests',
        'main_number' => '+966 50 000 0000',
        'alert_key_expiring' => 'touchwood_key_expiring',
        'alert_key_refused' => 'touchwood_key_refused',
    ]]);
    DB::table('ops.sms_countries')->where('country_code', 'SA')->update(['provider' => 'whatsapp']);
    Http::preventStrayRequests();
    CapturedEmails::install();
    cache()->flush();
    whatsAppForgetBanner();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The notice remembers the list for a minute, in the banner store (a file, which no test rolls back). */
function whatsAppForgetBanner(): void
{
    Cache::store((string) config('cache.banner_store', 'file'))->forget('ops.whatsapp_banner');
}

/** Meta taking a message: the answer with its id. */
function whatsAppTakes(string $id = 'wamid.AAA'): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '966501234567', 'wa_id' => '966501234567']], 'messages' => [['id' => $id]]], 200)]);
}

/** Meta refusing: its error JSON with the numeric code. */
function whatsAppRefuses(int $status, int $code): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Meta said no', 'type' => 'OAuthException', 'code' => $code, 'fbtrace_id' => 'x']], $status)]);
}

/** @return list<array<string, mixed>> */
function whatsAppRows(string $kind = 'security'): array
{
    return array_values(array_map(static fn (object $row): array => (array) $row, DB::table('ops.sent_messages')->where('kind', $kind)->orderBy('created_at')->orderBy('id')->get()->all()));
}

/** @return list<string> the staff ids with an entry of the kind */
function whatsAppBell(string $kind = 'security_sms_failed'): array
{
    return array_column(array_map(static fn (object $row): array => (array) $row, DB::table('ops.inbox_entries')->where('kind', $kind)->get()->all()), 'staff_id');
}

/**
 * A notice as Meta signs it: the raw body, and the server variables a call carries it with.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $server
 * @return TestResponse<Response>
 */
function whatsAppPost(array $body, ?string $secret = WHATSAPP_SECRET, array $server = []): TestResponse
{
    $raw = (string) json_encode($body);
    $headers = ['CONTENT_TYPE' => 'application/json'] + $server;

    if ($secret !== null) {
        $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
    }

    return call('POST', '/whatsapp/webhook', [], [], [], $headers, $raw);
}

/** @return array<string, mixed> */
function whatsAppStatusBody(string $id, string $status, string $numberId = WHATSAPP_NUMBER_ID): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '966500000001', 'phone_number_id' => $numberId],
        'statuses' => [['id' => $id, 'status' => $status, 'timestamp' => '1700000000', 'recipient_id' => '966501234567']],
    ]]]]]];
}

/** @return array<string, mixed> */
function whatsAppMessageBody(string $from, string $id, string $numberId = WHATSAPP_NUMBER_ID): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '966500000001', 'phone_number_id' => $numberId],
        'contacts' => [['profile' => ['name' => 'Someone'], 'wa_id' => $from]],
        'messages' => [['from' => $from, 'id' => $id, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => 'hello']]],
    ]]]]]];
}

function whatsAppAutoReplyRows(): int
{
    return DB::table('ops.whatsapp_auto_replies')->count();
}

describe('a code', function () {
    it('goes as the authentication template in the person\'s language, with the code twice, for each purpose', function (string $locale, bool $signIn) {
        whatsAppTakes('wamid.CODE1');

        $signIn
            ? app(SecurityMessages::class)->staffSignInCode('+966501234567', $locale, '482913')
            : app(SecurityMessages::class)->phoneCode('+966501234567', $locale, '482913');

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === WHATSAPP_SEND_URL
            && $request->hasHeader('Authorization', 'Bearer token-for-tests')
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => '+966501234567',
                'type' => 'template',
                'template' => [
                    'name' => 'touchwood_code',
                    'language' => ['code' => $locale],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '482913']]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '482913']]],
                    ],
                ],
            ]);

        $rows = whatsAppRows();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['channel'])->toBe('WHATSAPP')
            ->and($rows[0]['status'])->toBe('SENT')
            ->and($rows[0]['provider_id'])->toBe('wamid.CODE1')
            ->and((string) json_encode($rows[0]))->not->toContain('482913')
            ->and((string) json_encode($rows[0]))->not->toContain('501234567');
    })->with([
        'phone check, English' => ['en', false],
        'phone check, Arabic' => ['ar', false],
        'sign in, English' => ['en', true],
        'sign in, Arabic' => ['ar', true],
    ]);

    it('writes its row QUEUED before the call, so a fast status finds it', function () {
        $seen = null;
        Http::fake(['graph.facebook.com/*' => function () use (&$seen) {
            $seen = array_column(whatsAppRows(), 'status');

            return Http::response(['messages' => [['id' => 'wamid.Q']]], 200);
        }]);

        app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913');

        expect($seen)->toBe(['QUEUED'])
            ->and(array_column(whatsAppRows(), 'status'))->toBe(['SENT']);
    });

    it('is a setup failure when Meta refuses the token, the template or the request: the person is told codes cannot go, a Super Admin is told, once', function (int $status, int $code) {
        $super = Fx::staff(superAdmin: true);
        whatsAppRefuses($status, $code);

        foreach ([1, 2] as $_) {
            expect(fn () => app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913'))->toThrow(CodesUnavailable::class);
        }

        $emails = CapturedEmails::installed()->sent;

        expect(array_column(whatsAppRows(), 'status'))->toBe(['FAILED', 'FAILED'])
            ->and(whatsAppBell())->toBe([$super])
            ->and($emails)->toHaveCount(1)
            ->and($emails[0]->text)->not->toContain('482913');
    })->with([
        'a bad token' => [401, 190],
        'a template not approved' => [404, 132001],
        'a bad request' => [400, 100],
    ]);

    it('is a per-person failure when that number has no WhatsApp: a failed row, the error to the caller, no alert to anybody', function () {
        Fx::staff(superAdmin: true);
        whatsAppRefuses(400, 131026);

        try {
            app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913');
            $failure = null;
        } catch (ProviderFailed $failed) {
            $failure = $failed;
        }

        expect($failure)->toBeInstanceOf(ProviderFailed::class)
            ->and($failure?->kind)->toBe(FailureKind::OneAddress)
            ->and(array_column(whatsAppRows(), 'status'))->toBe(['FAILED'])
            ->and(whatsAppBell())->toBe([])
            ->and(CapturedEmails::installed()->sent)->toBe([]);
    });

    it('is an ordinary failure when Meta is away: the caller sees it, and the Super Admins hear of it', function () {
        $super = Fx::staff(superAdmin: true);
        Http::fake(['graph.facebook.com/*' => Http::response('<html>bad gateway</html>', 502)]);

        expect(fn () => app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913'))->toThrow(ProviderFailed::class);
        expect(whatsAppBell())->toBe([$super]);
    });

    it('stays as it was on the log provider: the same words in the log', function () {
        DB::table('ops.sms_countries')->where('country_code', 'SA')->update(['provider' => 'log']);
        Http::fake();

        app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913');

        Http::assertNothingSent();
        expect(array_column(whatsAppRows(), 'channel'))->toBe(['WHATSAPP']);
    });
});

describe('a missing setting (W3)', function () {
    it('refuses a code with its words, sends nothing, tells the Super Admins by bell and email at most once an hour', function () {
        $super = Fx::staff(superAdmin: true);
        config(['services.whatsapp.access_token' => '']);
        Http::fake();

        foreach ([1, 2, 3] as $_) {
            expect(fn () => app(SecurityMessages::class)->staffSignInCode('+966501234567', 'en', '482913'))->toThrow(CodesUnavailable::class);
        }

        Http::assertNothingSent();
        expect(whatsAppBell())->toBe([$super])
            ->and(CapturedEmails::installed()->sent)->toHaveCount(1);

        CarbonImmutable::setTestNow('2026-11-22 11:05:00');
        expect(fn () => app(SecurityMessages::class)->staffSignInCode('+966501234567', 'en', '482913'))->toThrow(CodesUnavailable::class);

        expect(whatsAppBell())->toBe([$super, $super])
            ->and(CapturedEmails::installed()->sent)->toHaveCount(2);
    });

    it('answers a request for a code with the words, in both languages, and not with an error page of the developers\'', function () {
        Route::get('whatsapp-test-codes', static fn () => throw new CodesUnavailable);

        $detail = getJson('/whatsapp-test-codes')->assertStatus(503)->json('detail');

        // The words of the language the request is answered in, never the developers' message.
        expect([
            trans('ops::errors.codes_unavailable.detail', [], 'en'),
            trans('ops::errors.codes_unavailable.detail', [], 'ar'),
        ])->toBe(['Codes can\'t be sent right now. Try again later.', 'تعذّر إرسال الرموز الآن. حاول مرة أخرى لاحقًا.'])
            ->and($detail)->toBeIn([trans('ops::errors.codes_unavailable.detail', [], 'en'), trans('ops::errors.codes_unavailable.detail', [], 'ar')]);
    });

    it('keeps the site running with every setting empty: the sign-in page opens, and the webhook refuses', function () {
        config(['services.whatsapp' => ['graph_version' => 'v26.0', 'graph_url' => 'https://graph.facebook.com']]);

        get('/admin/sign-in')->assertOk();
        call('GET', '/whatsapp/webhook', ['hub.mode' => 'subscribe', 'hub.verify_token' => '', 'hub.challenge' => '1'])->assertForbidden();
        whatsAppPost(whatsAppStatusBody('wamid.X', 'failed'), '')->assertForbidden();
    });

    it('shows the red notice to whoever manages the Code Countries, while a country names WhatsApp and a setting is missing', function () {
        $super = Fx::staff(superAdmin: true);
        $admin = Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin);
        config(['services.whatsapp.access_token' => '']);
        $banners = static fn (): array => app(WhatsAppBanner::class)->forCurrentActor('en', static fn (): array => []);

        Fx::actAsStaff($super);
        $shown = $banners();

        expect($shown)->toHaveCount(1)
            ->and($shown[0]->text)->toBe('WhatsApp is not set up: no codes can be sent.')
            ->and([$shown[0]->everywhere, $shown[0]->error])->toBe([true, true]);

        // Not for someone who cannot manage the list, and nothing on a development machine on `log`.
        Fx::actAsStaff($admin);
        expect($banners())->toBe([]);

        whatsAppForgetBanner();
        DB::table('ops.sms_countries')->update(['provider' => 'log']);
        Fx::actAsStaff($super);
        expect($banners())->toBe([]);
    });

    it('has no notice when everything is set up', function () {
        expect(app(WhatsAppBanner::class)->forCurrentActor('en', static fn (): array => []))->toBe([]);
    });
});

describe('the webhook\'s handshake', function () {
    it('answers the challenge when the mode is subscribe and the token is ours', function () {
        call('GET', '/whatsapp/webhook', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-for-tests', 'hub.challenge' => '1158201444'])
            ->assertOk()->assertSee('1158201444');
    });

    it('refuses a wrong token, a wrong mode and a missing challenge', function (array $query) {
        call('GET', '/whatsapp/webhook', $query)->assertForbidden();
    })->with([
        'a wrong token' => [['hub.mode' => 'subscribe', 'hub.verify_token' => 'nope', 'hub.challenge' => '1']],
        'a wrong mode' => [['hub.mode' => 'unsubscribe', 'hub.verify_token' => 'verify-for-tests', 'hub.challenge' => '1']],
        'no challenge' => [['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-for-tests']],
        'no token' => [['hub.mode' => 'subscribe', 'hub.challenge' => '1']],
    ]);
});

describe('the webhook\'s notices', function () {
    it('refuses a missing or a wrong signature, and a signature of another secret, doing nothing', function (?string $secret) {
        Queue::fake();

        whatsAppPost(whatsAppStatusBody('wamid.X', 'failed'), $secret)->assertForbidden();

        Queue::assertNothingPushed();
    })->with(['missing' => [null], 'wrong' => ['another-secret']]);

    it('refuses a signature made of a different body', function () {
        Queue::fake();
        $raw = (string) json_encode(whatsAppStatusBody('wamid.X', 'failed'));

        call('POST', '/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw.' ', WHATSAPP_SECRET)], $raw)->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('refuses a notice for a phone number that is not ours', function () {
        Queue::fake();

        whatsAppPost(whatsAppStatusBody('wamid.X', 'failed', '999'))->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('refuses a body over its cap with 413, before reading it', function () {
        Queue::fake();

        call('POST', '/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=x'], str_repeat('a', WhatsAppWebhookController::MOST_BYTES + 1))->assertStatus(413);

        Queue::assertNothingPushed();
    });

    it('is throttled by address', function () {
        RateLimiter::increment('ops-whatsapp-webhook:127.0.0.1', 60, WhatsAppWebhookController::CALLS_A_MINUTE_AN_ADDRESS);

        whatsAppPost(whatsAppStatusBody('wamid.X', 'failed'))->assertStatus(429);
    });

    it('is Ops\' reserved path, with no session or CSRF token: a signed POST with neither is taken, answered at once with no body', function () {
        expect(app(ReservedPaths::class)->isReserved('whatsapp'))->toBeTrue();

        whatsAppPost(whatsAppStatusBody('wamid.X', 'delivered'))->assertOk()->assertSee('', false);
    });

    it('is still taken while the site is in maintenance: Meta would keep sending a 503 for days', function () {
        Queue::fake();
        app()->maintenanceMode()->activate([]);

        try {
            whatsAppPost(whatsAppStatusBody('wamid.X', 'failed'))->assertOk();
            // Only this address: a person's pages still answer the maintenance page.
            get('/sa/en')->assertStatus(503);
        } finally {
            app()->maintenanceMode()->deactivate();
        }

        Queue::assertPushed(HandleWhatsAppStatusJob::class, 1);
    });

    it('queues only a failed status, and nothing for sent, delivered or read', function () {
        Queue::fake();

        foreach (['sent', 'delivered', 'read'] as $status) {
            whatsAppPost(whatsAppStatusBody('wamid.X', $status))->assertOk();
        }

        Queue::assertNothingPushed();

        whatsAppPost(whatsAppStatusBody('wamid.X', 'failed'))->assertOk();

        Queue::assertPushed(HandleWhatsAppStatusJob::class, static fn (HandleWhatsAppStatusJob $job): bool => $job->providerId === 'wamid.X');
        Queue::assertPushed(HandleWhatsAppStatusJob::class, 1);
    });
});

describe('a later status', function () {
    it('turns a SENT row FAILED by Meta\'s id, once: a duplicate changes nothing', function () {
        whatsAppTakes('wamid.LATE');
        app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913');

        whatsAppPost(whatsAppStatusBody('wamid.LATE', 'failed'))->assertOk();

        expect(array_column(whatsAppRows(), 'status'))->toBe(['FAILED']);

        whatsAppPost(whatsAppStatusBody('wamid.LATE', 'failed'))->assertOk();

        expect(array_column(whatsAppRows(), 'status'))->toBe(['FAILED'])
            ->and(app(SentMessages::class)->markFailedByProviderId('wamid.LATE'))->toBeFalse();
    });

    it('changes nothing for delivered or read: the row stays SENT, with no new state', function () {
        whatsAppTakes('wamid.OK');
        app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913');

        foreach (['delivered', 'read'] as $status) {
            whatsAppPost(whatsAppStatusBody('wamid.OK', $status))->assertOk();
        }

        expect(array_column(whatsAppRows(), 'status'))->toBe(['SENT']);
    });

    it('never turns a row that is not SENT: a QUEUED one stays, and an unknown id is nothing', function () {
        $id = strtolower((string) Str::ulid());
        app(SentMessages::class)->record(new SentMessage($id, MessageKind::Security, null, null, null, MessageChannel::Whatsapp, 'wamid.WAIT', SendStatus::Queued, CarbonImmutable::now()->toDateTimeImmutable()));

        (new HandleWhatsAppStatusJob('wamid.WAIT'))->handle(app(SentMessages::class));
        (new HandleWhatsAppStatusJob('wamid.NOBODY'))->handle(app(SentMessages::class));

        expect(DB::table('ops.sent_messages')->where('id', $id)->value('status'))->toBe('QUEUED');
    });
});

describe('the auto-reply', function () {
    it('answers a person who writes, once, in both languages with the main number\'s link, and records it', function () {
        whatsAppTakes('wamid.REPLY');

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN1'))->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            $body = $request->data();

            return $request->url() === WHATSAPP_SEND_URL
                && $body['type'] === 'text'
                && $body['to'] === '966555000111'
                && str_contains($body['text']['body'], 'هذا الرقم مخصص لإرسال رموز التحقق فقط ولا يستقبل الرسائل. للتواصل معنا راسلنا على رقمنا الرئيسي: https://wa.me/966500000000')
                && str_contains($body['text']['body'], 'This number only sends verification codes and can\'t read replies. To reach us, message our main number: https://wa.me/966500000000');
        });

        $rows = whatsAppRows('auto_reply');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['channel'])->toBe('WHATSAPP')
            ->and($rows[0]['status'])->toBe('SENT')
            ->and($rows[0]['provider_id'])->toBe('wamid.REPLY')
            ->and(whatsAppAutoReplyRows())->toBe(1);
    });

    it('keeps the sender only as a keyed HMAC, never the number', function () {
        whatsAppTakes();

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN1'))->assertOk();

        $stored = (string) DB::table('ops.whatsapp_auto_replies')->value('sender_hmac');
        $key = base64_decode(substr((string) config('app.key'), 7), true);

        expect($stored)->toBe(hash_hmac('sha256', '966555000111', (string) $key))
            ->and($stored)->not->toBe(hash('sha256', '966555000111'))
            ->and($stored)->not->toContain('966555000111')
            ->and((string) json_encode(DB::table('ops.sent_messages')->get()))->not->toContain('966555000111');
    });

    it('answers a sender once in 24 hours, even to another message, and again after the day', function () {
        whatsAppTakes();

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN1'))->assertOk();
        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN2'))->assertOk();
        Http::assertSentCount(1);

        CarbonImmutable::setTestNow('2026-11-23 09:59:59');
        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN3'))->assertOk();
        Http::assertSentCount(1);

        CarbonImmutable::setTestNow('2026-11-23 10:00:00');
        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN4'))->assertOk();
        Http::assertSentCount(2);
        expect(whatsAppAutoReplyRows())->toBe(1);
    });

    it('handles one message id once, however often Meta sends it', function () {
        whatsAppTakes();

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.SAME'))->assertOk();
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.SAME'))->assertOk();

        Http::assertSentCount(1);
    });

    it('answers no status, and no message from our own numbers', function () {
        whatsAppTakes();

        foreach (['sent', 'delivered', 'read', 'failed'] as $status) {
            whatsAppPost(whatsAppStatusBody('wamid.S', $status))->assertOk();
        }

        whatsAppPost(whatsAppMessageBody('966500000000', 'wamid.MAIN'))->assertOk();
        whatsAppPost(whatsAppMessageBody('966500000001', 'wamid.OWN'))->assertOk();

        Http::assertNothingSent();
        expect(whatsAppAutoReplyRows())->toBe(0);
    });

    it('stops at 100 an hour in total, and starts again when the hour has passed', function () {
        whatsAppTakes();

        for ($i = 0; $i < 99; $i++) {
            DB::table('ops.whatsapp_auto_replies')->insert(['sender_hmac' => hash('sha256', "filler{$i}"), 'replied_at' => '2026-11-22 09:30:00+00']);
        }

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.A'))->assertOk();
        whatsAppPost(whatsAppMessageBody('966555000222', 'wamid.B'))->assertOk();

        Http::assertSentCount(1);
        expect(whatsAppAutoReplyRows())->toBe(100);

        CarbonImmutable::setTestNow('2026-11-22 10:31:00');
        whatsAppPost(whatsAppMessageBody('966555000222', 'wamid.C'))->assertOk();

        Http::assertSentCount(2);
    });

    it('raises no alert when its send fails, and is not tried again', function () {
        Fx::staff(superAdmin: true);
        whatsAppRefuses(400, 100);

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN1'))->assertOk();

        $rows = whatsAppRows('auto_reply');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['status'])->toBe('FAILED')
            ->and(whatsAppBell())->toBe([])
            ->and(DB::table('ops.inbox_entries')->count())->toBe(0)
            ->and(CapturedEmails::installed()->sent)->toBe([]);

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN2'))->assertOk();
        Http::assertSentCount(1);
    });

    it('sends nothing with the main number not set', function () {
        config(['services.whatsapp.main_number' => '']);
        Http::fake();

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN1'))->assertOk();

        Http::assertNothingSent();
        expect(whatsAppRows('auto_reply'))->toBe([]);
    });

    it('forgets a claim after 24 hours, in the nightly removal', function () {
        DB::table('ops.whatsapp_auto_replies')->insert([
            ['sender_hmac' => hash('sha256', 'old'), 'replied_at' => '2026-11-21 09:59:59+00'],
            ['sender_hmac' => hash('sha256', 'new'), 'replied_at' => '2026-11-21 10:00:01+00'],
        ]);

        $gone = Fx::asSystem(fn (): array => app(PruneOpsLogHandler::class)->handle(new PruneOpsLog));

        expect($gone['whatsapp_auto_replies'])->toBe(1)
            ->and(whatsAppAutoReplyRows())->toBe(1);
    });

    it('refuses a sender that is not a 64-character hex HMAC, in the code and in the table', function () {
        expect(fn () => new AutoReplySender('966555000111'))->toThrow(InvalidOpsInput::class)
            ->and(fn () => new AutoReplySender(strtoupper(str_repeat('a', 64))))->toThrow(InvalidOpsInput::class)
            ->and((new AutoReplySender(str_repeat('a', 64)))->hmac)->toBe(str_repeat('a', 64));

        expect(fn () => DB::transaction(fn () => DB::table('ops.whatsapp_auto_replies')->insert(['sender_hmac' => str_repeat('A', 64), 'replied_at' => now()])))->toThrow(QueryException::class, 'whatsapp_auto_replies_hmac')
            ->and(fn () => DB::transaction(fn () => DB::table('ops.whatsapp_auto_replies')->insert(['sender_hmac' => '966555000111', 'replied_at' => now()])))->toThrow(QueryException::class, 'whatsapp_auto_replies_hmac');
    });

    it('is built by the container with the claim before the send', function () {
        expect(app(WhatsAppAutoReply::class)->senderOf('+966 55 500 0111')->hmac)->toBe(app(WhatsAppAutoReply::class)->senderOf('966555000111')->hmac);
    });
});

describe('the table\'s rules', function () {
    it('takes the channel WHATSAPP and the kind auto_reply, and refuses any other channel', function () {
        $row = static fn (string $channel, string $kind): array => ['id' => strtolower((string) Str::ulid()), 'kind' => $kind, 'channel' => $channel, 'status' => 'SENT', 'created_at' => now()];

        DB::table('ops.sent_messages')->insert($row('WHATSAPP', 'security'));
        DB::table('ops.sent_messages')->insert($row('WHATSAPP', 'auto_reply'));

        expect(fn () => DB::transaction(fn () => DB::table('ops.sent_messages')->insert($row('FAX', 'security'))))->toThrow(QueryException::class, 'sent_messages_channel')
            ->and(DB::table('ops.sent_messages')->where('channel', 'WHATSAPP')->count())->toBe(2);
    });

    it('refuses an auto_reply row on the EMAIL channel in the code, as the kind does not send one', function () {
        expect(fn () => new SentMessage(strtolower((string) Str::ulid()), MessageKind::AutoReply, null, null, null, MessageChannel::Email, null, SendStatus::Sent, CarbonImmutable::now()->toDateTimeImmutable()))->toThrow(InvalidOpsInput::class);
    });

    it('looks a row up by Meta\'s id on its own index', function () {
        DB::statement('SET enable_seqscan = off');
        $planned = collect(DB::select("EXPLAIN SELECT 1 FROM ops.sent_messages WHERE provider_id = 'x'"))->pluck('QUERY PLAN')->implode(' ');

        expect($planned)->toContain('sent_messages_by_provider_id');
    });
});

describe('the critical alerts (point 9, W1)', function () {
    function whatsAppSuper(string $locale = 'en', bool $switch = true): string
    {
        $super = Fx::staff(superAdmin: true);
        DB::table('access.staff_users')->where('id', $super)->update(['critical_sms' => $switch, 'locale' => $locale]);

        return $super;
    }

    function whatsAppKey(int $days): GatewayKeyExpiring
    {
        return new GatewayKeyExpiring((string) Str::uuid(), Fx::storeId('sa'), 'LIVE', $days, '2026-11-30', CarbonImmutable::now());
    }

    it('sends the key-expiring template at 1 day to a Super Admin with the switch on and a phone, in their language', function (string $locale) {
        $super = whatsAppSuper($locale);
        whatsAppTakes('wamid.ALERT1');

        event(whatsAppKey(30));
        event(whatsAppKey(1));

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === WHATSAPP_SEND_URL
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => (string) DB::table('access.staff_users')->where('id', $super)->value('phone'),
                'type' => 'template',
                'template' => ['name' => 'touchwood_key_expiring', 'language' => ['code' => $locale]],
            ]);

        $rows = DB::table('ops.sent_messages')->where('kind', 'gateway_key_expiring')->where('channel', 'WHATSAPP')->get(['status', 'provider_id'])->all();

        expect($rows)->toHaveCount(1)
            ->and(array_column(array_map(static fn (object $row): array => (array) $row, $rows), 'status'))->toBe(['SENT'])
            ->and(array_column(array_map(static fn (object $row): array => (array) $row, $rows), 'provider_id'))->toBe(['wamid.ALERT1']);
    })->with(['en', 'ar']);

    it('sends the key-refused template when the gateway refuses the key', function () {
        whatsAppSuper();
        whatsAppTakes();

        event(new GatewayKeyRejected((string) Str::uuid(), Fx::storeId('sa'), 'LIVE', CarbonImmutable::now()));

        Http::assertSent(static fn (Request $request): bool => ($request->data()['template']['name'] ?? null) === 'touchwood_key_refused');
        Http::assertSentCount(1);
    });

    it('goes to nobody else: not a Super Admin with the switch off, nor an Admin', function () {
        whatsAppSuper('en', false);
        Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin);
        Http::fake();

        event(whatsAppKey(1));

        Http::assertNothingSent();
    });
});

describe('the review\'s findings', function () {
    it('keeps the sender\'s number out of the queue\'s payload: the job is encrypted (M1)', function () {
        config(['queue.default' => 'database']);
        whatsAppTakes();
        DB::table('jobs')->delete();

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN1'))->assertOk();

        $payloads = DB::table('jobs')->pluck('payload')->all();

        expect($payloads)->toHaveCount(1)
            ->and($payloads[0])->not->toContain('966555000111')
            ->and($payloads[0])->not->toContain('wamid.IN1');
        expect(class_implements(HandleWhatsAppMessageJob::class))->toHaveKey(ShouldBeEncrypted::class);
    });

    it('throttles the handshake with the same limiter as the notices (M2)', function () {
        RateLimiter::increment('ops-whatsapp-webhook:127.0.0.1', 60, WhatsAppWebhookController::CALLS_A_MINUTE_AN_ADDRESS);

        call('GET', '/whatsapp/webhook', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-for-tests', 'hub.challenge' => '1'])->assertStatus(429);
    });

    it('looks again, a bounded number of times, for a status that beat its row (L5)', function (int $attempt, bool $released) {
        $job = new HandleWhatsAppStatusJob('wamid.NOBODY');
        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('attempts')->andReturn($attempt);
        $released ? $queued->shouldReceive('release')->once() : $queued->shouldNotReceive('release');
        /** @var Job $queued */
        $job->setJob($queued);

        $job->handle(app(SentMessages::class));
    })->with([
        'the first look' => [1, true],
        'the fourth look' => [4, true],
        'the last look lets go' => [HandleWhatsAppStatusJob::WAITS, false],
    ]);

    it('turns a row at once when its id is already there, and never waits for a duplicate (L5)', function () {
        whatsAppTakes('wamid.FAST');
        app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913');

        $job = new HandleWhatsAppStatusJob('wamid.FAST');
        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('attempts')->andReturn(1);
        $queued->shouldNotReceive('release');
        /** @var Job $queued */
        $job->setJob($queued);

        $job->handle(app(SentMessages::class));
        $job->handle(app(SentMessages::class));

        expect(array_column(whatsAppRows(), 'status'))->toBe(['FAILED']);
    });

    it('spends nobody\'s 24 hours while a setting is missing (L6)', function () {
        config(['services.whatsapp.main_number' => '']);
        Http::fake();

        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN1'))->assertOk();

        expect(whatsAppAutoReplyRows())->toBe(0);
        Http::assertNothingSent();

        config(['services.whatsapp.main_number' => '+966 50 000 0000']);
        whatsAppTakes();
        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN2'))->assertOk();

        Http::assertSentCount(1);
    });

    it('refuses an APP_KEY that is not valid base64, rather than keying the HMAC with nothing (L7)', function () {
        config(['app.key' => 'base64:@@@not-base64@@@']);

        expect(fn () => app(WhatsAppAutoReply::class)->senderOf('966555000111'))->toThrow(LogicException::class, 'APP_KEY');
    });

    it('puts nothing personal in the application log: not the phone, not the code, not Meta\'s words, on a send, a refusal, a status and a reply (L9)', function () {
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $answer = static fn (): Illuminate\Http\Client\Response|Illuminate\Http\Client\PendingRequest|GuzzleHttp\Promise\PromiseInterface => Http::response(['messages' => [['id' => 'wamid.LOG1']]], 200);
        Http::fake(['graph.facebook.com/*' => static function () use (&$answer) {
            return $answer();
        }]);
        app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913');
        $answer = static fn () => Http::response(['error' => ['message' => 'Meta said no', 'code' => 100]], 400);
        expect(fn () => app(SecurityMessages::class)->staffSignInCode('+966501234567', 'en', '482913'))->toThrow(CodesUnavailable::class);
        $answer = static fn () => Http::response(['error' => ['message' => 'Meta said no', 'code' => 131026]], 400);
        expect(fn () => app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913'))->toThrow(ProviderFailed::class);
        $answer = static fn () => Http::response(['messages' => [['id' => 'wamid.LOG2']]], 200);
        whatsAppPost(whatsAppStatusBody('wamid.LOG1', 'failed'))->assertOk();
        whatsAppPost(whatsAppMessageBody('966555000111', 'wamid.IN1'))->assertOk();

        $everything = implode("\n", $logged);

        expect($everything)->not->toContain('966501234567')
            ->and($everything)->not->toContain('501234567')
            ->and($everything)->not->toContain('482913')
            ->and($everything)->not->toContain('966555000111')
            ->and($everything)->not->toContain('Meta said no');
    });
});

<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Presentation\Http\Controller\PaymentNoticeController;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\MyFatoorahAnswers as Answers;

use function Pest\Laravel\call;
use function Pest\Laravel\seed;

/*
| MyFatoorah's notice at a store's and mode's own address (payments.md §1.2, §1.8, §5): the signature its
| authority, kept once, and nothing of the card or the customer kept. What it sets off is step 10's.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    RateLimiter::clear('payments-notice-ip:127.0.0.1');
});

function noticesKey(string $store, string $mode = 'TEST', string $secret = 'whsec_secret_1'): void
{
    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, $mode, 'sk_key_ABC123', $secret, '2027-12-31'));
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function noticesPost(string $store, string $mode, string $body, ?string $signature, array $headers = []): TestResponse
{
    $server = array_merge(['CONTENT_TYPE' => 'application/json'], $signature === null ? [] : ['HTTP_MYFATOORAH_SIGNATURE' => $signature], $headers);

    return call('POST', "/payments/notice/{$store}/{$mode}", [], [], [], $server, $body);
}

it('keeps a signed notice, with nothing of the card or the customer, and answers 200 with no body', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $notice = Answers::notice('whsec_secret_1');

    $response = noticesPost($store, 'test', $notice['body'], $notice['signature']);

    $row = DB::table('payments.notices')->sole();
    $payload = (string) $row->payload;

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toBe('')
        ->and([$row->store_id, $row->mode, $row->gateway, $row->event_reference, $row->handled_at])->toBe([$store, 'TEST', 'MYFATOORAH', 'evt-1', null])
        ->and(json_decode($payload, true)['Data']['Transaction']['Card'])->toBe(['Brand' => 'Visa'])
        ->and(json_decode($payload, true)['Data']['Invoice']['Id'])->toBe('7320290');

    foreach (['446404xxxxxx0007', 'a-card-token', '88.253.45.0', 'A Buyer', '+966500000000', 'buyer@example.test'] as $private) {
        expect($payload)->not->toContain($private);
    }
});

it('takes a notice with no session or CSRF token, and opens none', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $notice = Answers::notice('whsec_secret_1');

    $response = noticesPost($store, 'test', $notice['body'], $notice['signature']);

    expect($response->status())->toBe(200)
        ->and($response->headers->getCookies())->toBe([]);
});

it('keeps a notice sent again once', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $notice = Answers::notice('whsec_secret_1');

    expect(noticesPost($store, 'test', $notice['body'], $notice['signature'])->status())->toBe(200)
        ->and(noticesPost($store, 'test', $notice['body'], $notice['signature'])->status())->toBe(200)
        ->and(DB::table('payments.notices')->count())->toBe(1);
});

it('refuses a bad signature with 401 and no body, keeps nothing, and only logs the store and mode', function (Closure $signature) {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = [$message->level, $message->message, $message->context];
    });
    $notice = Answers::notice('whsec_secret_1');

    $response = noticesPost($store, 'test', $notice['body'], $signature($notice['signature']));

    expect($response->status())->toBe(401)
        ->and($response->getContent())->toBe('')
        ->and(DB::table('payments.notices')->count())->toBe(0)
        ->and($logged)->toBe([['warning', 'A payment notice failed its signature check.', ['store_id' => $store, 'mode' => 'TEST']]]);
})->with([
    'none' => [fn (string $good): ?string => null],
    'empty' => [fn (string $good): string => ''],
    'another secret\'s' => [fn (string $good): string => Answers::signature('another-secret')],
    'not Base64' => [fn (string $good): string => 'nonsense'],
    'in other letters' => [fn (string $good): string => strtolower($good)],
]);

it('refuses a signed field changed on the way', function (Closure $change) {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $notice = Answers::notice('whsec_secret_1');

    expect(noticesPost($store, 'test', $change($notice['body']), $notice['signature'])->status())->toBe(401);
})->with([
    'the invoice' => [fn (string $body): string => str_replace('"7320290"', '"7320291"', $body)],
    'its status' => [fn (string $body): string => str_replace('"Status":"PAID"', '"Status":"PENDING"', $body)],
    'the transaction\'s status' => [fn (string $body): string => str_replace('"Status":"SUCCESS"', '"Status":"FAILED"', $body)],
    'the payment' => [fn (string $body): string => str_replace('07077320290391998273', '07077320290391998274', $body)],
    'our attempt' => [fn (string $body): string => str_replace('01k8attempt', '01k8another', $body)],
]);

it('signs a missing value as nothing, as MyFatoorah does', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $notice = Answers::notice('whsec_secret_1', external: null);

    expect(noticesPost($store, 'test', $notice['body'], $notice['signature'])->status())->toBe(200);
});

it('accepts an earlier key\'s secret while it is kept, and never another mode\'s or store\'s', function () {
    $store = Fx::storeId('sa');
    noticesKey($store, secret: 'whsec_old');
    noticesKey($store, secret: 'whsec_new');
    noticesKey($store, 'LIVE', 'whsec_live');
    noticesKey(Fx::storeId('eg'), 'TEST', 'whsec_egypt');

    $sent = fn (string $mode, string $secret, string $reference): int => noticesPost($store, $mode, Answers::notice($secret, $reference)['body'], Answers::notice($secret, $reference)['signature'])->status();

    expect($sent('test', 'whsec_new', 'a'))->toBe(200)
        ->and($sent('test', 'whsec_old', 'b'))->toBe(200)
        ->and($sent('live', 'whsec_live', 'c'))->toBe(200)
        ->and($sent('test', 'whsec_live', 'd'))->toBe(401)
        ->and($sent('live', 'whsec_new', 'e'))->toBe(401)
        ->and($sent('test', 'whsec_egypt', 'f'))->toBe(401)
        ->and(DB::table('payments.notices')->orderBy('event_reference')->pluck('mode', 'event_reference')->all())->toBe(['a' => 'TEST', 'b' => 'TEST', 'c' => 'LIVE']);
});

it('keeps the same reference apart in each mode', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);
    noticesKey($store, 'LIVE', 'whsec_live');

    noticesPost($store, 'test', Answers::notice('whsec_secret_1')['body'], Answers::notice('whsec_secret_1')['signature']);
    noticesPost($store, 'live', Answers::notice('whsec_live')['body'], Answers::notice('whsec_live')['signature']);

    expect(DB::table('payments.notices')->count())->toBe(2);
});

it('refuses a store with no key, one that does not exist, and a body that is not JSON', function () {
    $notice = Answers::notice('whsec_secret_1');
    $store = Fx::storeId('sa');

    expect(noticesPost($store, 'test', $notice['body'], $notice['signature'])->status())->toBe(401)
        ->and(noticesPost(strtolower((string) Str::ulid()), 'test', $notice['body'], $notice['signature'])->status())->toBe(401);

    noticesKey($store);

    expect(noticesPost($store, 'test', 'not json', $notice['signature'])->status())->toBe(401)
        ->and(noticesPost($store, 'test', '"a string"', $notice['signature'])->status())->toBe(401);
});

it('thanks a notice of another kind and keeps nothing: it is not read, and must not be sent again', function (?string $signature) {
    $store = Fx::storeId('sa');
    $notice = Answers::notice('whsec_secret_1', code: 2);

    expect(noticesPost($store, 'test', $notice['body'], $signature)->status())->toBe(200)
        ->and(DB::table('payments.notices')->count())->toBe(0);
})->with(['with one' => ['any'], 'with none' => [null]]);

it('thanks and drops a signed notice it could not store, rather than answering 500', function (Closure $body) {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $notice = Answers::notice('whsec_secret_1');

    expect(noticesPost($store, 'test', $body($notice['body']), $notice['signature'])->status())->toBe(200)
        ->and(DB::table('payments.notices')->count())->toBe(0);
})->with([
    'a very long reference' => [fn (string $body): string => str_replace('"evt-1"', '"'.str_repeat('r', 201).'"', $body)],
    'a NUL in it' => [fn (string $body): string => str_replace('"10.10"', '"10\\u0000"', $body)],
    'a number too big to write back' => [fn (string $body): string => str_replace('"10.10"', '1e999', $body)],
]);

it('keeps nothing of a signed notice with no reference', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $notice = Answers::notice('whsec_secret_1', reference: '');

    expect(noticesPost($store, 'test', $notice['body'], $notice['signature'])->status())->toBe(200)
        ->and(DB::table('payments.notices')->count())->toBe(0);
});

it('has no such address for another mode or what is not a store id, and takes only a POST', function () {
    $store = Fx::storeId('sa');

    expect(noticesPost($store, 'TEST', '{}', 'x')->status())->toBe(404)
        ->and(noticesPost($store, 'sometimes', '{}', 'x')->status())->toBe(404)
        ->and(noticesPost('sa', 'test', '{}', 'x')->status())->toBe(404)
        ->and(call('GET', "/payments/notice/{$store}/test")->status())->toBe(405);
});

it('turns away the call over 120 a minute for a store, before any work', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);
    $notice = Answers::notice('whsec_secret_1');

    foreach (range(1, PaymentNoticeController::CALLS_A_MINUTE) as $call) {
        expect(noticesPost($store, 'test', $notice['body'], 'x')->status())->toBe(401);
    }

    $response = noticesPost($store, 'test', $notice['body'], $notice['signature']);

    expect($response->status())->toBe(429)
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and(DB::table('payments.notices')->count())->toBe(0)
        ->and(noticesPost(Fx::storeId('eg'), 'test', $notice['body'], $notice['signature'])->status())->toBe(401);

    RateLimiter::clear('payments-notice:'.$store);
});

it('turns away one address over 600 a minute, whatever store it names', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);

    foreach (range(1, PaymentNoticeController::CALLS_A_MINUTE_AN_ADDRESS) as $call) {
        expect(noticesPost(strtolower((string) Str::ulid()), 'test', Answers::notice('another')['body'], 'x')->status())->toBe(401);
    }

    expect(noticesPost($store, 'test', '{}', 'x')->status())->toBe(429);

    RateLimiter::clear('payments-notice-ip:127.0.0.1');
});

it('answers 413 to what is too large to be a notice', function () {
    $store = Fx::storeId('sa');
    noticesKey($store);

    expect(noticesPost($store, 'test', str_repeat('x', PaymentNoticeController::MOST_BYTES + 1), 'x')->status())->toBe(413);
});

it('still takes a notice for a store switched off: its open attempts finish', function () {
    $store = Fx::storeId('eg');
    noticesKey($store);
    DB::table('platform.stores')->where('id', $store)->update(['is_active' => false]);
    $notice = Answers::notice('whsec_secret_1');

    expect(noticesPost($store, 'test', $notice['body'], $notice['signature'])->status())->toBe(200);
});

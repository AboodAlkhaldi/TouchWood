<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Payments\Application\Gateway\ChargeAnswer;
use Modules\Payments\Application\Gateway\ChargeRequest;
use Modules\Payments\Application\Gateway\GatewayAccess;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Model\GatewayCall;
use Modules\Payments\Domain\Repository\GatewayCalls;
use Modules\Payments\Public\Enums\PaymentMode;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\MyFatoorahAnswers as Answers;

use function Pest\Laravel\seed;

/*
| The client against a faked MyFatoorah, in the shapes its test server gave (payments.md §9.8): what it sends,
| what it logs, and what it takes each answer to mean. No test calls it.
*/

uses(RefreshDatabase::class);

const CLIENT_MF = 'https://apitest.myfatoorah.com';

const CLIENT_PAYMENT = CLIENT_MF.'/v3/payments/07077320290391998273';

beforeEach(function () {
    seed(PlatformSeeder::class);
    Sleep::fake();
});

function clientAccess(): GatewayAccess
{
    return new GatewayAccess(Fx::storeId('sa'), PaymentMode::Test, CLIENT_MF, strtolower((string) Str::ulid()), 'sk_test_the_secret_key');
}

function clientCalls(): int
{
    return DB::table('payments.calls')->count();
}

describe('opening a session', function () {
    it('sends the methods, the networks, the amount and the expiry, in Collect Details mode, with the key', function () {
        Http::fake([CLIENT_MF.'/v3/sessions' => Http::response(Answers::session(), 201)]);

        $opened = app(GatewayClient::class)->openSession(clientAccess(), '1180.50', 'SAR', 'ar', new CarbonImmutable('2027-01-01T13:15:00+03:00'));

        $request = Http::recorded()->map(fn (array $pair): Request => $pair[0])->sole();
        expect($request->method())->toBe('POST')
            ->and($request->header('Authorization'))->toBe(['Bearer sk_test_the_secret_key'])
            ->and($request->data())->toBe([
                'PaymentMode' => 'COLLECT_DETAILS',
                'Order' => ['Amount' => 1180.5, 'Currency' => 'SAR'],
                'OperationType' => 'PAY',
                'SupportedNetworks' => ['visa', 'masterCard', 'mada'],
                'SupportedPaymentMethods' => ['card', 'applepay', 'stcpay'],
                'SessionExpiry' => '2027-01-01T10:15:00Z',
                'Language' => 'AR',
            ])
            ->and($request->body())->toContain('"Amount":1180.5,')
            ->and($opened->id)->toBe('KWT-a5f60574-ddf0-46b9-a30e-102b231604aa')
            ->and($opened->expiresAt->getTimestamp())->toBe(CarbonImmutable::parse('2027-01-01T10:15:00Z')->getTimestamp());
    });

    it('asks once: a page with no answer offers Retry, and a session charges nothing', function () {
        Http::fake([CLIENT_MF.'/*' => Http::response([], 503)]);

        expect(fn () => app(GatewayClient::class)->openSession(clientAccess(), '10.00', 'SAR', 'en', new CarbonImmutable('+1 hour')))->toThrow(GatewayUnreachable::class)
            ->and(clientCalls())->toBe(1);
        Sleep::assertNeverSlept();
    });

    it('does not take a session with no id for one, nor one too long to keep', function (array $data) {
        Http::fake([CLIENT_MF.'/*' => Http::response(['IsSuccess' => true, 'Data' => $data], 201)]);

        expect(fn () => app(GatewayClient::class)->openSession(clientAccess(), '10.00', 'SAR', 'en', new CarbonImmutable('+1 hour')))->toThrow(GatewayUnreachable::class);
    })->with([
        'no id' => [[]],
        'too long' => [['SessionId' => str_repeat('x', 101)]],
    ]);

    it('keeps the end we asked for when the answer\'s cannot be read', function () {
        Http::fake([CLIENT_MF.'/*' => Http::response(Answers::session(expiry: 'not a date'), 201)]);
        $end = new CarbonImmutable('2027-01-01T10:15:00Z');

        expect(app(GatewayClient::class)->openSession(clientAccess(), '10.00', 'SAR', 'en', $end)->expiresAt->getTimestamp())->toBe($end->getTimestamp());
    });
});

describe('asking about a payment', function () {
    it('gives the invoice, the transaction and every amount as the exact text MyFatoorah sent', function () {
        Http::fake([CLIENT_PAYMENT => Http::response(Answers::payment())]);

        $answer = app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273');

        expect([$answer->invoiceId, $answer->invoiceStatus, $answer->externalIdentifier, $answer->paymentId, $answer->transactionStatus, $answer->method, $answer->transactionDate])
            ->toBe(['7320290', 'PAID', '01k8attempt', '07077320290391998273', 'SUCCESS', 'VISA/MASTER', '2026-10-28T19:33:08.2470000Z'])
            ->and([$answer->errorCode, $answer->errorMessage, $answer->cardBrand])->toBe([null, null, 'Visa'])
            ->and([$answer->baseCurrency, $answer->baseAmount, $answer->displayCurrency, $answer->displayAmount, $answer->payCurrency, $answer->payAmount])
            ->toBe(['KWD', '0.81', 'SAR', '10.10', 'KWD', '0.81']);
    });

    it('names a decline', function () {
        Http::fake([CLIENT_PAYMENT => Http::response(Answers::payment('FAILED', 'PENDING', 'MF003', 'BLOCKED'))]);

        $answer = app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273');

        expect([$answer->transactionStatus, $answer->invoiceStatus, $answer->errorCode, $answer->errorMessage])->toBe(['FAILED', 'PENDING', 'MF003', 'BLOCKED']);
    });

    it('asks three times, five seconds apart, then says it does not know - logging every try', function () {
        Http::fake([CLIENT_PAYMENT => Http::response([], 503)]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayUnreachable::class)
            ->and(clientCalls())->toBe(3);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);
    });

    it('takes the answer of a later try, after no answer at all and after a limit', function () {
        Http::fakeSequence(CLIENT_PAYMENT)
            ->pushResponse(Http::failedConnection())
            ->push([], 429)
            ->push(Answers::payment());

        expect(app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273')->transactionStatus)->toBe('SUCCESS')
            ->and(DB::table('payments.calls')->orderBy('id')->pluck('http_status')->all())->toBe([null, 429, 200])
            ->and(DB::table('payments.calls')->orderBy('id')->pluck('error')->all())->toBe(['No answer', null, null]);
    });

    it('survives a connection that throws', function () {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayUnreachable::class)
            ->and(clientCalls())->toBe(3);
    });

    it('does not ask again after the key was refused, and says so', function () {
        Http::fake([CLIENT_PAYMENT => Http::response(['IsSuccess' => false, 'Message' => 'Unauthorized'], 401)]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayKeyRefused::class)
            ->and(clientCalls())->toBe(1);
    });

    it('tells a permission the key lacks from a key refused, naming the call', function () {
        Http::fake([CLIENT_PAYMENT => Http::response(['IsSuccess' => false, 'Message' => 'The token does not have the required permissions!'], 401)]);

        try {
            app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273');

            throw new LogicException('Expected a refusal');
        } catch (KeyPermissionMissing $e) {
            expect($e->permission)->toBe('GET /v3/payments/07077320290391998273');
        }
    });

    it('asks again after a 4xx that says "not now": it is no refusal', function (int $status) {
        Http::fake([CLIENT_PAYMENT => Http::response(['IsSuccess' => false, 'Message' => 'Busy'], $status)]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayUnreachable::class)
            ->and(clientCalls())->toBe(3);
    })->with([408, 409, 423, 425, 429]);

    it('does not follow a redirect: it asks again, then says it does not know', function () {
        Http::fake([CLIENT_PAYMENT => Http::response('', 302, ['Location' => 'https://elsewhere.example.test/'])]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayUnreachable::class)
            ->and(clientCalls())->toBe(3);
    });

    it('reads IsSuccess false as text too', function () {
        Http::fake([CLIENT_PAYMENT => Http::response(['IsSuccess' => 'false', 'Message' => 'No'])]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayRefused::class);
    });

    it('does not take a 4xx it cannot read for a no', function () {
        Http::fake([CLIENT_PAYMENT => Http::response('<html>Forbidden</html>', 403)]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayUnreachable::class)
            ->and(clientCalls())->toBe(3);
    });

    it('keeps the answer when writing the call down fails, and logs that with no key', function () {
        Http::fake([CLIENT_PAYMENT => Http::response(Answers::payment())]);
        app()->instance(GatewayCalls::class, new class implements GatewayCalls
        {
            public function record(GatewayCall $call): void
            {
                throw new RuntimeException('The log is down');
            }
        });
        $logged = [];
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = (string) json_encode([$message->level, $message->message, $message->context]);
        });

        $answer = app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273');

        expect($answer->transactionStatus)->toBe('SUCCESS')
            ->and(implode(' ', $logged))->toContain('could not be logged')
            ->and(implode(' ', $logged))->not->toContain('sk_test_the_secret_key');
    });

    it('cuts a very long address to what the log can hold', function () {
        Http::fake([CLIENT_MF.'/*' => Http::response(Answers::payment())]);

        app(GatewayClient::class)->paymentDetails(clientAccess(), str_repeat('9', 400));

        expect(strlen((string) DB::table('payments.calls')->value('endpoint')))->toBe(300);
    });

    it('does not ask again after MyFatoorah said no, in a 4xx or in an answer of 200', function (int $status) {
        Http::fake([CLIENT_PAYMENT => Http::response(Answers::refusal('Not here', 'No such payment'), $status)]);

        try {
            app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273');

            throw new LogicException('Expected a refusal');
        } catch (GatewayRefused $e) {
            expect($e->reason)->toBe('Not here No such payment');
        }

        expect(clientCalls())->toBe(1);
    })->with([404, 400, 200]);

    it('takes a 4xx that does not say IsSuccess for a no all the same', function () {
        Http::fake([CLIENT_PAYMENT => Http::response(['Message' => 'Bad request'], 400)]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayRefused::class)
            ->and(clientCalls())->toBe(1);
    });

    it('never takes an answer it cannot read for a no', function (string $body) {
        Http::fake([CLIENT_PAYMENT => Http::response($body)]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayUnreachable::class);
    })->with([
        'not JSON' => ['<html>Bad gateway</html>'],
        'no transaction' => ['{"IsSuccess":true,"Data":{"Invoice":{"Id":"1","Status":"PAID"}}}'],
        'no data' => ['{"IsSuccess":true}'],
    ]);
});

describe('asking about a session', function () {
    it('says a session was never charged', function () {
        Http::fake([CLIENT_MF.'/v3/sessions/KWT-1' => Http::response(['IsSuccess' => true, 'Data' => ['IsUsed' => false, 'TransactionResult' => null]])]);

        $answer = app(GatewayClient::class)->sessionDetails(clientAccess(), 'KWT-1');

        expect([$answer->isUsed, $answer->payment])->toBe([false, null]);
    });

    it('gives what came of a session that was', function () {
        Http::fake([CLIENT_MF.'/v3/sessions/KWT-1' => Http::response(['IsSuccess' => true, 'Data' => ['IsUsed' => true, 'TransactionResult' => Answers::paymentData()]])]);

        $answer = app(GatewayClient::class)->sessionDetails(clientAccess(), 'KWT-1');

        expect([$answer->isUsed, $answer->payment?->paymentId])->toBe([true, '07077320290391998273']);
    });

    it('never says "never charged" unless the answer says so clearly', function (array $data) {
        Http::fake([CLIENT_MF.'/v3/sessions/KWT-1' => Http::response(['IsSuccess' => true, 'Data' => $data])]);

        expect(fn () => app(GatewayClient::class)->sessionDetails(clientAccess(), 'KWT-1'))->toThrow(GatewayUnreachable::class);
    })->with([
        'no IsUsed' => [['TransactionResult' => null]],
        'used, with no result' => [['IsUsed' => true, 'TransactionResult' => null]],
        'used, with a result it cannot read' => [['IsUsed' => true, 'TransactionResult' => ['Invoice' => ['Id' => '1', 'Status' => 'PENDING']]]],
        'unused, yet with a result it cannot read' => [['IsUsed' => false, 'TransactionResult' => ['Invoice' => ['Id' => '1']]]],
        'a result that is not a list' => [['IsUsed' => false, 'TransactionResult' => 'odd']],
    ]);
});

describe('the log', function () {
    it('keeps the call, how long it took, and the attempt it belongs to, with neither key nor card nor customer', function () {
        Http::fake([
            CLIENT_MF.'/v3/sessions' => Http::response(Answers::session(), 201),
            CLIENT_PAYMENT => Http::response(Answers::payment()),
        ]);
        $attempt = strtolower((string) Str::ulid());
        $access = new GatewayAccess(Fx::storeId('sa'), PaymentMode::Test, CLIENT_MF, strtolower((string) Str::ulid()), 'sk_test_the_secret_key', $attempt);

        app(GatewayClient::class)->openSession($access, '10.00', 'SAR', 'en', new CarbonImmutable('+1 hour'));
        app(GatewayClient::class)->paymentDetails($access, '07077320290391998273');

        $calls = DB::table('payments.calls')->orderBy('id')->get();
        $everything = (string) json_encode($calls);

        expect($calls->pluck('endpoint')->all())->toBe(['POST /v3/sessions', 'GET /v3/payments/07077320290391998273'])
            ->and($calls->pluck('http_status')->all())->toBe([201, 200])
            ->and($calls->pluck('attempt_id')->unique()->all())->toBe([$attempt])
            ->and($calls->pluck('store_id')->unique()->all())->toBe([Fx::storeId('sa')])
            ->and($calls->every(fn (object $call): bool => is_int($call->took_ms) && $call->took_ms >= 0))->toBeTrue();

        foreach (['sk_test_the_secret_key', 'Bearer', 'the-session-encryption-key', 'EncryptionKey', '446404xxxxxx0007', 'test test', 'a-card-token', 'a-pan-hash', '88.253.45.0', 'buyer@example.test', '+966500000000', 'Anonymous'] as $secret) {
            expect($everything)->not->toContain($secret);
        }

        expect(json_decode((string) $calls->last()?->response, true)['Data']['Transaction']['Card'])->toBe(['Brand' => 'Visa'])
            ->and(json_decode((string) $calls->first()?->request, true)['Order']['Currency'])->toBe('SAR');
    });

    it('logs an answer it could not read as nothing, with its status', function () {
        Http::fake([CLIENT_PAYMENT => Http::response('<html>sk_test_the_secret_key</html>', 502)]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayUnreachable::class);

        $call = DB::table('payments.calls')->orderBy('id')->first();

        expect($call?->http_status)->toBe(502)
            ->and($call?->response)->toBeNull();
    });
});

it('takes only a clear yes for registering a domain', function (array $answer) {
    Http::fake([CLIENT_MF.'/v2/RegisterApplePayDomain' => Http::response($answer)]);

    expect(fn () => app(GatewayClient::class)->registerApplePayDomain(clientAccess(), 'shop.example.com'))->toThrow(GatewayUnreachable::class);
})->with([
    'nothing' => [[]],
    'words alone' => [['Message' => 'Done']],
]);

it('registers a domain for Apple Pay', function () {
    Http::fake([CLIENT_MF.'/v2/RegisterApplePayDomain' => Http::response(['IsSuccess' => true, 'Data' => []])]);

    app(GatewayClient::class)->registerApplePayDomain(clientAccess(), 'shop.example.com');

    $request = Http::recorded()->map(fn (array $pair): Request => $pair[0])->sole();
    expect($request->data())->toBe(['DomainName' => 'shop.example.com']);
});

describe('charging', function () {
    function clientCharge(?DateTimeImmutable $expiry = null): ChargeAnswer
    {
        return app(GatewayClient::class)->charge(clientAccess(), new ChargeRequest('KWT-1', '1180.50', 'SAR', 'the-key', 'attempt-1', 'try-1', $expiry ?? new CarbonImmutable('+5 minutes'), 'ar', 'https://shop.example.test/return', 'https://shop.example.test/notice'));
    }

    it('sends the session, the amount, our id, the addresses and the idempotency key', function () {
        Http::fake([CLIENT_MF.'/v3/payments' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceId' => '7', 'PaymentId' => '9', 'PaymentURL' => 'https://demo.example.test/pay']], 201)]);

        $answer = clientCharge(new CarbonImmutable('2027-01-01T13:05:00+03:00'));

        $request = Http::recorded()->map(fn (array $pair): Request => $pair[0])->sole();
        expect([$answer->invoiceId, $answer->paymentId, $answer->frameUrl])->toBe(['7', '9', 'https://demo.example.test/pay'])
            ->and($request->header('Idempotency-Key'))->toBe(['the-key'])
            ->and($request->data())->toBe([
                'SourceOfFund' => ['SessionId' => 'KWT-1'],
                'Order' => ['Amount' => 1180.5, 'Currency' => 'SAR', 'ExternalIdentifier' => 'attempt-1'],
                'OperationType' => 'PAY',
                'PaymentExpiry' => '2027-01-01T10:05:00Z',
                'Customer' => ['Reference' => 'try-1'],
                'IntegrationUrls' => ['Redirection' => 'https://shop.example.test/return', 'Webhook' => 'https://shop.example.test/notice'],
                'Language' => 'AR',
            ]);
    });

    it('takes the first call\'s no for a no, a key\'s refusal for that, and logs the key in neither', function () {
        Http::fake([CLIENT_MF.'/v3/payments' => Http::sequence()
            ->push(Answers::refusal('SessionId is expired!'), 400)
            ->push(['IsSuccess' => false, 'Message' => 'Unauthorized'], 401)
            ->push(['IsSuccess' => false, 'Message' => 'The token does not have the required permissions!'], 401)]);

        expect(fn () => clientCharge())->toThrow(GatewayRefused::class)
            ->and(fn () => clientCharge())->toThrow(GatewayKeyRefused::class)
            ->and(fn () => clientCharge())->toThrow(KeyPermissionMissing::class)
            ->and((string) json_encode(DB::table('payments.calls')->get()))->not->toContain('sk_test_the_secret_key');
    });

    it('never reads a yes it cannot use as a no', function (array $data) {
        Http::fake([CLIENT_MF.'/v3/payments' => Http::response(['IsSuccess' => true, 'Data' => $data], 201)]);

        expect(fn () => clientCharge())->toThrow(GatewayUnreachable::class)
            ->and(clientCalls())->toBe(1);
    })->with([
        'no invoice' => [['PaymentId' => '9']],
        'no payment' => [['InvoiceId' => '7']],
        'an id too long to keep' => [['InvoiceId' => str_repeat('7', 65), 'PaymentId' => '9']],
    ]);

    it('offers no bank page it could not keep', function () {
        Http::fake([CLIENT_MF.'/v3/payments' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceId' => '7', 'PaymentId' => '9', 'PaymentURL' => 'https://x/'.str_repeat('y', 2000)]], 201)]);

        expect(clientCharge()->frameUrl)->toBeNull();
    });
});

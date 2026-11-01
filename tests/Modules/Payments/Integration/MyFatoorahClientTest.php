<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Payments\Application\Gateway\GatewayAccess;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
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

    it('does not take a session with no id for one', function () {
        Http::fake([CLIENT_MF.'/*' => Http::response(['IsSuccess' => true, 'Data' => []], 201)]);

        expect(fn () => app(GatewayClient::class)->openSession(clientAccess(), '10.00', 'SAR', 'en', new CarbonImmutable('+1 hour')))->toThrow(GatewayUnreachable::class);
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
        Http::fake([CLIENT_PAYMENT => Http::response(['IsSuccess' => false, 'Message' => 'The token does not have the required permissions!'], 401)]);

        expect(fn () => app(GatewayClient::class)->paymentDetails(clientAccess(), '07077320290391998273'))->toThrow(GatewayKeyRefused::class)
            ->and(clientCalls())->toBe(1);
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

    it('does not take an answer without IsUsed for "never charged"', function () {
        Http::fake([CLIENT_MF.'/v3/sessions/KWT-1' => Http::response(['IsSuccess' => true, 'Data' => ['TransactionResult' => null]])]);

        expect(fn () => app(GatewayClient::class)->sessionDetails(clientAccess(), 'KWT-1'))->toThrow(GatewayUnreachable::class);
    });
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

it('registers a domain for Apple Pay', function () {
    Http::fake([CLIENT_MF.'/v2/RegisterApplePayDomain' => Http::response(['IsSuccess' => true, 'Data' => []])]);

    app(GatewayClient::class)->registerApplePayDomain(clientAccess(), 'shop.example.com');

    $request = Http::recorded()->map(fn (array $pair): Request => $pair[0])->sole();
    expect($request->data())->toBe(['DomainName' => 'shop.example.com']);
});

<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Exception\KeyTestFailed;
use Modules\Payments\Infrastructure\External\MyFatoorahKeyTester;

/*
| The three test calls on a pasted key (payments.md §1.2), against a faked MyFatoorah: no test calls it.
*/

const MF = 'https://apitest.myfatoorah.com';

function testKey(): void
{
    app(MyFatoorahKeyTester::class)->test(MF, 'sk_test_key_ABC123', 'sar');
}

it('asks for the methods, opens a session that charges nothing, and asks for a payment that is not there', function () {
    Http::fake([
        MF.'/v3/payment-methods' => Http::response(['IsSuccess' => true]),
        MF.'/v3/sessions' => Http::response(['IsSuccess' => true]),
        MF.'/v3/payments/0' => Http::response(['IsSuccess' => false], 404),
    ]);

    testKey();

    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($sent->map(fn (Request $request): string => $request->method().' '.$request->url())->all())->toBe([
        'GET '.MF.'/v3/payment-methods',
        'POST '.MF.'/v3/sessions',
        'GET '.MF.'/v3/payments/0',
    ]);
    expect($sent->every(fn (Request $request): bool => $request->header('Authorization') === ['Bearer sk_test_key_ABC123']))->toBeTrue()
        ->and($sent->map(fn (Request $request): array => $request->data())->all()[1])->toBe(['PaymentMode' => 'COLLECT_DETAILS', 'Order' => ['Amount' => 1, 'Currency' => 'SAR'], 'OperationType' => 'PAY']);
});

it('names the permission a 401 says the key lacks, at each of the three calls', function (string $refusing, string $permission) {
    Http::fake([
        MF.'/v3/payment-methods' => $refusing === 'methods' ? Http::response([], 401) : Http::response([]),
        MF.'/v3/sessions' => $refusing === 'sessions' ? Http::response([], 401) : Http::response([]),
        MF.'/v3/payments/0' => $refusing === 'payments' ? Http::response([], 401) : Http::response([], 404),
    ]);

    try {
        testKey();

        throw new LogicException('Expected a refusal');
    } catch (KeyPermissionMissing $e) {
        expect($e->permission)->toBe($permission);
    }
})->with([
    ['methods', 'the payment methods'],
    ['sessions', 'the sessions'],
    ['payments', 'the payments'],
]);

it('fails the test when the methods or the session answer anything else', function () {
    Http::fake([
        MF.'/v3/payment-methods' => Http::response([]),
        MF.'/v3/sessions' => Http::response([], 400),
    ]);

    expect(fn () => testKey())->toThrow(KeyTestFailed::class, 'the sessions answered 400');
});

it('answers a gateway that is down, or not reached, as unreachable', function () {
    Http::fake([MF.'/*' => Http::response([], 503)]);
    expect(fn () => testKey())->toThrow(GatewayUnreachable::class);

    Http::fake(fn () => throw new ConnectionException('timed out'));
    expect(fn () => testKey())->toThrow(GatewayUnreachable::class);
});

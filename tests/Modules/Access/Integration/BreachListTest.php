<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Access\Infrastructure\Security\LoggedBreachList;

/**
 * @return array{value: string, threshold: int}
 */
function breachCheck(string $password): array
{
    return ['value' => $password, 'threshold' => 0];
}

it('is the verifier behind Laravel\'s uncompromised() rule', function () {
    expect(app(UncompromisedVerifier::class))->toBeInstanceOf(LoggedBreachList::class);
});

it('refuses a password the service lists, sending only the first 5 characters of its hash', function () {
    $hash = strtoupper(sha1('correct horse battery'));
    Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response("0000000000000000000000000000000000A:3\r\n".substr($hash, 5).":12\r\n")]);

    expect(app(UncompromisedVerifier::class)->verify(breachCheck('correct horse battery')))->toBeFalse()
        ->and(app(UncompromisedVerifier::class)->verify(breachCheck('another passphrase')))->toBeTrue();
    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), substr($hash, 5)));
});

it('accepts the password but logs it when the service answers with an error', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);
    $log = Log::spy();

    expect(app(UncompromisedVerifier::class)->verify(breachCheck('correct horse battery')))->toBeTrue();
    $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['status' => 503]);
});

it('accepts the password but reports it when the service cannot be reached', function () {
    Exceptions::fake();
    Http::fake(fn () => throw new ConnectionException('unreachable'));

    expect(app(UncompromisedVerifier::class)->verify(breachCheck('correct horse battery')))->toBeTrue();
    Exceptions::assertReported(ConnectionException::class);
});

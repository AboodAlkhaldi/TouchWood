<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Infrastructure\External\BrevoEmailSender;
use Modules\Ops\Infrastructure\External\LogEmailSender;
use Modules\Ops\Infrastructure\External\LogSmsSender;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/*
| The adapters (ops.md §2.2): email chosen by configuration - `log` in development and tests, Brevo -
| and SMS by the phone's country (SmsSenders; access.md amendment 90, tested with the security
| messages) - and Brevo's request exactly as its reference shows it
| (https://developers.brevo.com/reference/sendtransacemail). No real network: Http::fake answers, and
| refuses anything not faked.
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

function sendersEmail(): OutgoingEmail
{
    return new OutgoingEmail('shop@touchwood.test', 'TouchWood Saudi Arabia', 'sara@example.test', 'Sara Ali', 'Your order SA-0001 is approved', '<p>Hello Sara,</p>', 'Hello Sara,');
}

it('uses the log drivers by default, and writes what would be sent to the log', function () {
    expect(config('ops.email.driver'))->toBe('log')
        ->and(app(EmailSender::class))->toBeInstanceOf(LogEmailSender::class)
        ->and(app(SmsSenders::class)->named('log'))->toBeInstanceOf(LogSmsSender::class);

    $handler = new TestHandler;
    $logger = new Logger('ops', [$handler]);

    expect((new LogEmailSender($logger))->send(sendersEmail()))->toBeNull()
        ->and((new LogSmsSender($logger))->send('+966500000000', 'TouchWood: the payment key expires.'))->toBeNull();

    $records = array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $handler->getRecords());

    expect($records)->toBe([
        ['Email (log driver)', ['from' => 'shop@touchwood.test', 'to' => 'sara@example.test', 'subject' => 'Your order SA-0001 is approved', 'text' => 'Hello Sara,']],
        ['SMS (log driver)', ['to' => '+966500000000', 'message' => 'TouchWood: the payment key expires.']],
    ]);
});

it('chooses Brevo by configuration, with the key from the environment\'s config', function () {
    config(['ops.email.driver' => 'brevo', 'services.brevo.key' => 'test-key']);

    expect(app(EmailSender::class))->toBeInstanceOf(BrevoEmailSender::class);
});

it('refuses a driver it does not have, and the log drivers on a live system', function () {
    config(['ops.email.driver' => 'carrier-pigeon']);
    expect(fn () => app(EmailSender::class))->toThrow(InvalidArgumentException::class, 'OPS_EMAIL_DRIVER');

    expect(fn () => app(SmsSenders::class)->named('mobily'))->toThrow(ProviderFailed::class, 'No SMS provider is called "mobily"');

    config(['ops.email.driver' => 'log']);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(fn () => app(EmailSender::class))->toThrow(InvalidArgumentException::class, 'never runs in production')
            ->and(fn () => app(SmsSenders::class)->named('log'))->toThrow(ProviderFailed::class, 'never runs in production');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('sends Brevo exactly the request its reference shows, and returns its message id', function () {
    Http::fake([BrevoEmailSender::ENDPOINT => Http::response(['messageId' => '<201798300811.5787683@relay.domain.com>'], 201)]);

    $id = (new BrevoEmailSender(app(Factory::class), 'test-key'))->send(sendersEmail());

    expect($id)->toBe('<201798300811.5787683@relay.domain.com>');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.brevo.com/v3/smtp/email'
        && $request->header('api-key') === ['test-key']
        && $request->header('Accept') === ['application/json']
        && $request->data() === [
            'sender' => ['email' => 'shop@touchwood.test', 'name' => 'TouchWood Saudi Arabia'],
            'to' => [['email' => 'sara@example.test', 'name' => 'Sara Ali']],
            'subject' => 'Your order SA-0001 is approved',
            'htmlContent' => '<p>Hello Sara,</p>',
            'textContent' => 'Hello Sara,',
        ]);
});

it('reports a refusal or an unreachable Brevo as a provider failure that names no address or text', function () {
    Http::fake([BrevoEmailSender::ENDPOINT => Http::response(['code' => 'unauthorized', 'message' => 'Key not found'], 401)]);
    $sender = new BrevoEmailSender(app(Factory::class), 'wrong-key');

    // The whole message, so nothing more - no address, no text - can hide in it.
    expect(fn () => $sender->send(sendersEmail()))->toThrow(new ProviderFailed('Brevo refused the email: HTTP 401 (unauthorized).'));

    Http::fake([BrevoEmailSender::ENDPOINT => fn () => throw new ConnectionException('Connection refused')]);
    expect(fn () => $sender->send(sendersEmail()))->toThrow(ProviderFailed::class, 'could not be reached');
});

it('sends nothing to Brevo without a key', function () {
    Http::fake();

    expect(fn () => (new BrevoEmailSender(app(Factory::class), ''))->send(sendersEmail()))
        ->toThrow(ProviderFailed::class, 'BREVO_API_KEY');

    Http::assertNothingSent();
});

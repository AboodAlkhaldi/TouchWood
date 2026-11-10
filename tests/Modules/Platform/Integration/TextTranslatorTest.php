<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Infrastructure\External\DeepLTextTranslator;
use Modules\Platform\Infrastructure\External\FakeTextTranslator;
use Modules\Platform\Public\Contracts\TextTranslator;
use Modules\Platform\Public\Contracts\TranslationFailed;
use Modules\Platform\Public\Enums\TranslationFailure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/*
| The translator (content.md §2.2, §1.3; platform.md row L1), against a stand-in for DeepL: a PSR-18 client
| answering what the test queues, so nothing leaves the machine. The real account's test is PARKED for the
| owner (no token here): whether Arabic is taken as a source, the American English code, and marks.
*/

const TEXT_TRANSLATOR_TOKEN = 'secret-token-for-tests-1234:fx';

/**
 * A DeepL adapter whose every request goes to the queued answers; `$sent` collects what was sent.
 *
 * @param  list<Response|Throwable>  $answers
 * @param  ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}>  $sent
 */
function textTranslatorOver(array $answers, ArrayObject $sent): DeepLTextTranslator
{
    $stack = HandlerStack::create(new MockHandler($answers));
    $stack->push(Middleware::history($sent));

    return new DeepLTextTranslator(TEXT_TRANSLATOR_TOKEN, new Client(['handler' => $stack]), app(LoggerInterface::class));
}

/**
 * The request the adapter sent, by its place.
 *
 * @param  ArrayObject<int, mixed>  $sent
 */
function textTranslatorRequest(ArrayObject $sent, int $index = 0): RequestInterface
{
    $entry = $sent[$index] ?? null;

    if (! is_array($entry) || ! ($entry['request'] ?? null) instanceof RequestInterface) {
        throw new LogicException("No request {$index} was sent.");
    }

    return $entry['request'];
}

/** DeepL's answer to /v2/translate. */
function textTranslatorAnswer(string ...$texts): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'translations' => array_map(static fn (string $text): array => ['detected_source_language' => 'AR', 'text' => $text], $texts),
    ]));
}

/** The service's own refusal, with words that must reach nobody. */
function textTranslatorRefusal(int $status): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'Service words for status '.$status.' '.TEXT_TRANSLATOR_TOKEN]));
}

/**
 * The failure of one call, with every log line written during it.
 *
 * @return array{TranslationFailed, list<string>}
 */
function textTranslatorFailure(TextTranslator $translator): array
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    try {
        $translator->translate(['نص عربي'], 'ar', 'en');
    } catch (TranslationFailed $failure) {
        return [$failure, $logged];
    }

    throw new LogicException('The call did not fail.');
}

it('sends the texts to DeepL as one request, Arabic to American English, and gives each translation back in order', function () {
    $sent = new ArrayObject;
    $translator = textTranslatorOver([textTranslatorAnswer('Hello', 'The shelf')], $sent);

    expect($translator->isReady())->toBeTrue()
        ->and($translator->translate(['مرحبا', 'الرف'], 'ar', 'en'))->toBe(['Hello', 'The shelf'])
        ->and($sent)->toHaveCount(1);

    $request = textTranslatorRequest($sent);
    $body = json_decode((string) $request->getBody(), true);

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/v2/translate')
        ->and($body['text'])->toBe(['مرحبا', 'الرف'])
        ->and(strtolower((string) $body['source_lang']))->toBe('ar')
        ->and(strtolower((string) $body['target_lang']))->toBe('en-us')
        // Plain text unless asked: whether marks survive in Arabic is for the real account (§1.3).
        ->and($body)->not->toHaveKey('tag_handling');
});

it('asks for tag handling only when the caller keeps marks', function () {
    $sent = new ArrayObject;
    $translator = textTranslatorOver([textTranslatorAnswer('<b>Hello</b>')], $sent);

    $translator->translate(['<b>مرحبا</b>'], 'ar', 'en', keepMarks: true);

    expect(json_decode((string) textTranslatorRequest($sent)->getBody(), true)['tag_handling'])->toBe('xml');
});

it('turns every digit in the answer to 0-9', function () {
    $sent = new ArrayObject;
    $translator = textTranslatorOver([textTranslatorAnswer('Size ١٢٠ cm, ۳ doors')], $sent);

    expect($translator->translate(['المقاس ١٢٠ سم'], 'ar', 'en'))->toBe(['Size 120 cm, 3 doors']);
});

it('names each failure by its reason, and tries once only', function (int|string $answer, TranslationFailure $reason) {
    $sent = new ArrayObject;
    $failure = is_int($answer)
        ? textTranslatorRefusal($answer)
        : new ConnectException('cURL error 28: Operation timed out', new Request('POST', '/v2/translate'));
    // A second answer queued that works: a retry would take it and succeed.
    $translator = textTranslatorOver([$failure, textTranslatorAnswer('Hello')], $sent);

    [$failed] = textTranslatorFailure($translator);

    expect($failed->reason)->toBe($reason)
        ->and($sent)->toHaveCount(1);
})->with([
    'the month\'s allowance used up (456)' => [456, TranslationFailure::Quota],
    'too many requests (429)' => [429, TranslationFailure::Busy],
    'too many requests (529)' => [529, TranslationFailure::Busy],
    'a bad token (403)' => [403, TranslationFailure::Refused],
    'a bad token (401)' => [401, TranslationFailure::Refused],
    'a call it would not take (400)' => [400, TranslationFailure::Refused],
    'its own failure (500)' => [500, TranslationFailure::Unreachable],
    'unavailable (503)' => [503, TranslationFailure::Unreachable],
    'a timeout' => ['timeout', TranslationFailure::Unreachable],
]);

it('calls an answer with the wrong count, or none, no answer', function (Response $answer) {
    $sent = new ArrayObject;
    [$failed] = textTranslatorFailure(textTranslatorOver([$answer], $sent));

    expect($failed->reason)->toBe(TranslationFailure::Unreachable);
})->with([
    'two for one' => fn (): Response => textTranslatorAnswer('One', 'Two'),
    'not JSON' => fn (): Response => new Response(200, [], 'not json'),
    'no translations' => fn (): Response => new Response(200, [], '{"other":[]}'),
]);

it('keeps the token and the service\'s words out of the failure and every log line', function (int|string $answer) {
    $sent = new ArrayObject;
    $failure = is_int($answer)
        ? textTranslatorRefusal($answer)
        : new ConnectException('cURL error 7: '.TEXT_TRANSLATOR_TOKEN, new Request('POST', '/v2/translate'));

    [$failed, $logged] = textTranslatorFailure(textTranslatorOver([$failure], $sent));
    $everything = $failed->getMessage().' '.$failed.' '.implode(' ', $logged);

    expect($failed->getPrevious())->toBeNull()
        ->and($everything)->not->toContain(TEXT_TRANSLATOR_TOKEN)
        ->and($everything)->not->toContain('secret-token')
        ->and($everything)->not->toContain('Service words')
        // Nor the text sent.
        ->and($everything)->not->toContain('نص عربي')
        // The token went where it belongs, and nowhere else.
        ->and(textTranslatorRequest($sent)->getHeaderLine('Authorization'))->toContain(TEXT_TRANSLATOR_TOKEN);
})->with([403, 456, 500, 'connection']);

it('logs each failure for whoever keeps the server: its reason and the service\'s status, nothing else', function (int|string $answer, string $reason, ?int $status) {
    $sent = new ArrayObject;
    $failure = is_int($answer)
        ? textTranslatorRefusal($answer)
        : new ConnectException('cURL error 7: '.TEXT_TRANSLATOR_TOKEN, new Request('POST', '/v2/translate'));
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = ['message' => $event->message, 'context' => $event->context, 'level' => $event->level];
    });

    try {
        textTranslatorOver([$failure], $sent)->translate(['نص عربي'], 'ar', 'en');
    } catch (TranslationFailed) {
    }

    expect($logged)->toBe([['message' => 'Machine translation failed.', 'context' => ['reason' => $reason, 'status' => $status], 'level' => 'warning']]);
})->with([
    'the allowance' => [456, 'QUOTA', 456],
    'a bad token' => [403, 'REFUSED', 403],
    'busy' => [529, 'BUSY', 529],
    'no connection' => ['connection', 'UNREACHABLE', null],
]);

it('runs one try of 15 seconds at most, logs nothing and sends nothing about the machine', function () {
    $options = (new DeepLTextTranslator(TEXT_TRANSLATOR_TOKEN))->options();

    expect($options['max_retries'])->toBe(0)
        ->and($options['timeout'])->toBe(15.0)
        ->and($options['send_platform_info'])->toBeFalse()
        ->and($options)->not->toHaveKey('logger')
        // The library's own cURL client, which honours the timeout, on a real server.
        ->and($options)->not->toHaveKey('http_client');
});

it('is not ready with no token, and sends nothing then', function () {
    $sent = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler([textTranslatorAnswer('Hello')]));
    $stack->push(Middleware::history($sent));
    $translator = new DeepLTextTranslator('  ', new Client(['handler' => $stack]));

    [$failed] = textTranslatorFailure($translator);

    expect($translator->isReady())->toBeFalse()
        ->and($failed->reason)->toBe(TranslationFailure::Refused)
        ->and($sent)->toHaveCount(0);
});

it('refuses a language the site has not, and an empty text, before sending anything', function () {
    $sent = new ArrayObject;
    $translator = textTranslatorOver([textTranslatorAnswer('Hello')], $sent);

    expect(fn () => $translator->translate(['مرحبا'], 'ar', 'fr'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $translator->translate(['  '], 'ar', 'en'))->toThrow(InvalidArgumentException::class)
        ->and($translator->translate([], 'ar', 'en'))->toBe([])
        ->and($sent)->toHaveCount(0);
});

it('binds the fake for development and tests, and DeepL with the server\'s token when chosen', function () {
    expect(app(TextTranslator::class))->toBeInstanceOf(FakeTextTranslator::class);

    config(['services.deepl.driver' => 'deepl', 'services.deepl.key' => '']);
    expect(app(TextTranslator::class))->toBeInstanceOf(DeepLTextTranslator::class)
        ->and(app(TextTranslator::class)->isReady())->toBeFalse();

    config(['services.deepl.key' => TEXT_TRANSLATOR_TOKEN]);
    expect(app(TextTranslator::class)->isReady())->toBeTrue();

    config(['services.deepl.driver' => 'other']);
    expect(fn () => app(TextTranslator::class))->toThrow(InvalidArgumentException::class);
});

it('never runs the fake in production when it is named there', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['services.deepl.driver' => 'fake']);

    try {
        expect(fn () => app(TextTranslator::class))->toThrow(InvalidArgumentException::class, 'never runs in production');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('is DeepL in production when nothing is set, and not ready with no key: "not set up", never a failure', function (?string $driver) {
    app()->detectEnvironment(fn (): string => 'production');
    config(['services.deepl.driver' => $driver, 'services.deepl.key' => '']);

    try {
        expect(app(TextTranslator::class))->toBeInstanceOf(DeepLTextTranslator::class)
            ->and(app(TextTranslator::class)->isReady())->toBeFalse();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
})->with(['unset' => [null], 'empty' => ['']]);

it('is the fake off production when nothing is set', function () {
    config(['services.deepl.driver' => null]);

    expect(app(TextTranslator::class))->toBeInstanceOf(FakeTextTranslator::class);
});

it('gives a fake answer anyone can tell from a real one, and fails when told to', function () {
    $fake = new FakeTextTranslator;

    expect($fake->translate(['مرحبا ١'], 'ar', 'en'))->toBe(['[en] مرحبا 1']);

    $fake->failNext(TranslationFailure::Quota);
    expect(fn () => $fake->translate(['مرحبا'], 'ar', 'en'))->toThrow(TranslationFailed::class)
        ->and($fake->translate(['مرحبا'], 'ar', 'en'))->toBe(['[en] مرحبا'])
        ->and($fake->calls())->toHaveCount(3);

    $fake->notReady();
    expect($fake->isReady())->toBeFalse()
        ->and(fn () => $fake->translate(['مرحبا'], 'ar', 'en'))->toThrow(TranslationFailed::class);
});

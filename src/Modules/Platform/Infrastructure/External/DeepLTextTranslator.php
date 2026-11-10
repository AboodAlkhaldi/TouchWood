<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\External;

use DeepL\AuthorizationException;
use DeepL\ConnectionException;
use DeepL\DeepLException;
use DeepL\LanguageCode;
use DeepL\QuotaExceededException;
use DeepL\TextResult;
use DeepL\TooManyRequestsException;
use DeepL\TranslateTextOptions;
use DeepL\Translator;
use DeepL\TranslatorOptions;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\TextTranslator;
use Modules\Platform\Public\Contracts\TranslationFailed;
use Modules\Platform\Public\Enums\TranslationFailure;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Shared\Domain\Text\LatinDigits;
use Throwable;

/**
 * `TextTranslator` over DeepL's own PHP library, `deeplcom/deepl-php` (content.md §2.2; v1.21.0, read in
 * vendor/ at the build). The token is the server's, `DEEPL_API_KEY` in `.env` alone; empty, `isReady()` is
 * false and nothing is sent.
 *
 * **One request, no retry, 15 seconds** (§1.3): `MAX_RETRIES` 0 and `TIMEOUT` 15 on the library's own cURL
 * client. With a PSR-18 client given instead - the tests' stand-in - the library ignores `TIMEOUT` and the
 * client owns it (TranslatorOptions::HTTP_CLIENT). No logger is given, so the library logs nothing, and it
 * sends no details of the machine (`SEND_PLATFORM_INFO` false).
 *
 * **Nothing of the service reaches a log or a screen**: every failure, the library's own or anything thrown
 * inside it, becomes a fresh `TranslationFailed` holding only its reason and no previous exception - the
 * library's carries the service's answer, and a frame of its own may hold the token among its arguments.
 *
 * **Not verified without the real account** (content.md §2.2, §9 P1; PARKED for the owner): that the
 * account takes Arabic as a source, that `en-US` is the American English code it takes (`GET /v3/languages`
 * answers both), and whether marks survive in Arabic with `tag_handling` "xml" (§1.3 (a) or (b)). The codes
 * below are the library's own constants for Arabic and American English.
 */
final readonly class DeepLTextTranslator implements TextTranslator
{
    /** Seconds one call may take (§1.3). */
    public const float TIMEOUT = 15.0;

    /** The site's languages as the service's source codes. */
    private const array SOURCE = ['ar' => LanguageCode::ARABIC, 'en' => LanguageCode::ENGLISH];

    /** And as its target codes: English is American (Q15 (a), owner 2026-11-10). */
    private const array TARGET = ['ar' => LanguageCode::ARABIC, 'en' => LanguageCode::ENGLISH_AMERICAN];

    /**
     * @param  ClientInterface|null  $http  a PSR-18 client in place of the library's cURL one: the tests'
     *                                      stand-in for the service. Null on a real server
     */
    public function __construct(
        #[SensitiveParameter] private string $key,
        private ?ClientInterface $http = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function isReady(): bool
    {
        return trim($this->key) !== '';
    }

    public function translate(array $texts, string $from, string $to, bool $keepMarks = false): array
    {
        $source = self::SOURCE[$from] ?? throw new InvalidArgumentException("No such language: \"{$from}\".");
        $target = self::TARGET[$to] ?? throw new InvalidArgumentException("No such language: \"{$to}\".");

        foreach ($texts as $text) {
            if (trim($text) === '') {
                throw new InvalidArgumentException('Every text must be a string with words in it.');
            }
        }

        if ($texts === []) {
            return [];
        }

        // No token: refused before anything is sent. The caller asks isReady() first; this is the last line.
        if (! $this->isReady()) {
            throw new TranslationFailed(TranslationFailure::Refused);
        }

        $results = $this->call($texts, $source, $target, $keepMarks);

        // The same count back, each a translation: anything else is no answer.
        if (! is_array($results) || count($results) !== count($texts)) {
            throw new TranslationFailed(TranslationFailure::Unreachable);
        }

        $translated = [];

        foreach ($results as $result) {
            if (! $result instanceof TextResult) {
                throw new TranslationFailed(TranslationFailure::Unreachable);
            }

            $translated[] = LatinDigits::of($result->text);
        }

        return $translated;
    }

    /**
     * The library's options: one try, 15 seconds, no logger, nothing about the machine sent.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $options = [
            TranslatorOptions::MAX_RETRIES => 0,
            TranslatorOptions::TIMEOUT => self::TIMEOUT,
            TranslatorOptions::SEND_PLATFORM_INFO => false,
        ];

        if ($this->http !== null) {
            $options[TranslatorOptions::HTTP_CLIENT] = $this->http;
        }

        return $options;
    }

    /**
     * @param  non-empty-list<string>  $texts
     *
     * @throws TranslationFailed
     */
    private function call(array $texts, string $source, string $target, bool $keepMarks): mixed
    {
        try {
            $translator = new Translator($this->key, $this->options());

            // Tag handling only when asked: whether the marks survive in Arabic is for the real account to show.
            return $translator->translateText($texts, $source, $target, $keepMarks ? [TranslateTextOptions::TAG_HANDLING => 'xml'] : []);
        } catch (Throwable $failure) {
            $reason = self::reason($failure);

            // For whoever keeps the server: the reason and the service's status, never the token, the text
            // or the service's answer - the library's message holds the answer, so it is never written.
            $this->logger?->warning('Machine translation failed.', [
                'reason' => $reason->value,
                'status' => $failure instanceof DeepLException && is_int($failure->httpStatusCode) ? $failure->httpStatusCode : null,
            ]);

            throw new TranslationFailed($reason);
        }
    }

    private static function reason(Throwable $failure): TranslationFailure
    {
        return match (true) {
            $failure instanceof QuotaExceededException => TranslationFailure::Quota,
            $failure instanceof TooManyRequestsException => TranslationFailure::Busy,
            $failure instanceof AuthorizationException => TranslationFailure::Refused,
            $failure instanceof ConnectionException => TranslationFailure::Unreachable,
            // 529: the service is busy, as 429 (§1.3). The library has no class of its own for it.
            $failure instanceof DeepLException && $failure->httpStatusCode === 529 => TranslationFailure::Busy,
            $failure instanceof DeepLException && $failure->httpStatusCode !== null && $failure->httpStatusCode < 500 => TranslationFailure::Refused,
            default => TranslationFailure::Unreachable,
        };
    }
}

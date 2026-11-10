<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\External;

use InvalidArgumentException;
use Modules\Platform\Public\Contracts\TextTranslator;
use Modules\Platform\Public\Contracts\TranslationFailed;
use Modules\Platform\Public\Enums\TranslationFailure;
use Shared\Domain\Text\LatinDigits;

/**
 * `TextTranslator` for development and tests (content.md §2.2): nothing is sent anywhere. Each text comes
 * back marked with the language asked for - "[en] نص" - so a page using it can never pass for a real
 * translation, and its digits 0-9 as the real one's.
 *
 * **Tags are kept**, as the real one keeps them with `$keepMarks` (content.md §1.3 (a)): the text is given
 * back whole after its marking, so `<b>نص</b>` is `[en] <b>نص</b>` - the marking outside every tag, the
 * tags and the XML's entities as sent - and the editor sees bold and links survive.
 *
 * A test may make it not ready, or make its next call fail with a reason, and reads what it was asked.
 * Bound once for the whole process, so a test's settings reach the use case it calls.
 */
final class FakeTextTranslator implements TextTranslator
{
    private bool $ready = true;

    private ?TranslationFailure $failNext = null;

    /** @var list<array{texts: list<string>, from: string, to: string, keepMarks: bool}> */
    private array $calls = [];

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function translate(array $texts, string $from, string $to, bool $keepMarks = false): array
    {
        foreach ([$from, $to] as $language) {
            if (! in_array($language, ['ar', 'en'], true)) {
                throw new InvalidArgumentException("No such language: \"{$language}\".");
            }
        }

        foreach ($texts as $text) {
            if (trim($text) === '') {
                throw new InvalidArgumentException('Every text must be a string with words in it.');
            }
        }

        $this->calls[] = ['texts' => $texts, 'from' => $from, 'to' => $to, 'keepMarks' => $keepMarks];

        if (! $this->ready) {
            throw new TranslationFailed(TranslationFailure::Refused);
        }

        if ($this->failNext !== null) {
            $reason = $this->failNext;
            $this->failNext = null;

            throw new TranslationFailed($reason);
        }

        return array_map(static fn (string $text): string => LatinDigits::of("[{$to}] {$text}"), $texts);
    }

    /** As a server with no token set. */
    public function notReady(): void
    {
        $this->ready = false;
    }

    /** The next call fails with this reason, then calls work again. */
    public function failNext(TranslationFailure $reason): void
    {
        $this->failNext = $reason;
    }

    /**
     * @return list<array{texts: list<string>, from: string, to: string, keepMarks: bool}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}

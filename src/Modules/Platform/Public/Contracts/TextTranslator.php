<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * Machine translation of staff text (content.md §2.2, platform.md row L1): Content's Translate button,
 * and later Feedback's (F2, F5), which cannot depend on Content - so it is Platform's.
 *
 * One call is one request to the service, with no retry, waiting 15 seconds at most: a person is waiting
 * (§1.3). The caller keeps each call under the service's size limit - Content's 20,000 characters a press
 * does. Only staff text is ever sent, never a customer's data. No permission is checked here: the use case
 * that calls it checks its own (§2).
 */
interface TextTranslator
{
    /**
     * Whether a token is set on the server, so a call can be made at all. False: the Translate button is
     * drawn disabled with its reason ("Translation isn't set up on this server.").
     */
    public function isReady(): bool;

    /**
     * Each text translated, the same count back in the same order; every digit in the answers 0-9.
     *
     * @param  list<string>  $texts  each one block of text (a heading, a paragraph, a list item, a cell, an
     *                               image's description); none empty
     * @param  string  $from  the texts' language, the site's own code: "ar" or "en"
     * @param  string  $to  the language wanted, "ar" or "en"; English is American (Q15 (a), owner 2026-11-10)
     * @param  bool  $keepMarks  whether the texts are XML whose inline tags the service must keep in place
     *                           around the words they mark (§1.3 (a)); the answers are then XML too. Seen on
     *                           the real account, 2026-11-10: Arabic `<b>` came back on the right English
     *                           words, reordered
     * @return list<string>
     *
     * @throws TranslationFailed with its reason, and nothing of the service's answer
     * @throws \InvalidArgumentException for a language the site has not, or an empty text
     */
    public function translate(array $texts, string $from, string $to, bool $keepMarks = false): array;
}

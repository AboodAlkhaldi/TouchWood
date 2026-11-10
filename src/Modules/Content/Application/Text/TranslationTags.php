<?php

declare(strict_types=1);

namespace Modules\Content\Application\Text;

/**
 * One line of runs as the XML Translate sends with tag handling, and back (content.md §1.3 (a)): bold as
 * `<b>words</b>`, a link as `<a i="0">words</a>`, where `i` is the link's place in the line and **its address
 * stays here** - the service never sees it, so it cannot change it. A link holds its bold inside it
 * (`<a i="0"><b>words</b></a>`); runs side by side with the same link are one `<a>`. Text has `&`, `<` and
 * `>` written as `&amp;`, `&lt;` and `&gt;`, and no control character XML 1.0 forbids.
 *
 * **The answer is read for what carries weight** (`parse()`): only `<b>` and `<a i="N">` with an N that was
 * sent, `<b>` holding words alone, `<a>` holding words and `<b>`, every link sent there at least once, every
 * `&` one of XML's entities, no other tag or attribute. The service may split or join bold as it moves the
 * words, and may give one link to two places - the address is still ours - so neither the number of `<b>`
 * nor of each `<a>` is held to what was sent (coordinator's pick, PR #372 review). Anything else is no
 * answer for the marks, and the caller drops them for that box (`plain()`): **a tag the service writes never
 * becomes a mark it did not come from**.
 */
final class TranslationTags
{
    /** A tag or an entity, kept as its own part when the answer is cut. */
    private const string PARTS = '/(<[^>]*>|&[^;]*;)/';

    /** A link's opening tag exactly as `encode()` writes it: its index with no leading zero. */
    private const string LINK_OPEN = '/\A<a i="(0|[1-9][0-9]{0,3})">\z/';

    /** @var array<string, string> */
    private const array ENTITIES = ['&amp;' => '&', '&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&apos;' => "'"];

    /** A line break, a control character, or a line or paragraph separator: a space in one line of runs. */
    private const string LINE_BREAK = '/[\p{Cc}\p{Zl}\p{Zp}]+/u';

    /** A control character XML 1.0 forbids: every one but the tab, the line feed and the carriage return. */
    private const string NOT_XML = '/[^\P{Cc}\t\n\r]/u';

    /**
     * The runs as sent, and the addresses of their links by index.
     *
     * @param  list<array<string, mixed>>  $runs  a line's runs, as RichText keeps them
     * @return array{xml: string, links: list<string>}
     */
    public static function encode(array $runs): array
    {
        $xml = '';
        $links = [];
        $open = null;

        foreach ($runs as $run) {
            $link = is_string($run['link'] ?? null) ? $run['link'] : null;

            if ($open !== null && $link !== $open) {
                $xml .= '</a>';
                $open = null;
            }

            if ($link !== null && $open === null) {
                $xml .= '<a i="'.count($links).'">';
                $links[] = $link;
                $open = $link;
            }

            $words = self::escape(is_string($run['text'] ?? null) ? $run['text'] : '');
            $xml .= ($run['bold'] ?? false) === true ? '<b>'.$words.'</b>' : $words;
        }

        return ['xml' => $open === null ? $xml : $xml.'</a>', 'links' => $links];
    }

    /**
     * Words as XML text: `&`, `<` and `>` written as their entities, and each control character XML 1.0
     * forbids (a plain box may hold a vertical tab) a space. The words are UTF-8: the use case checks it.
     */
    public static function escape(string $words): string
    {
        return strtr(preg_replace(self::NOT_XML, ' ', $words) ?? $words, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    /**
     * The runs the translated line holds, each link's address given back by its index; null when the answer
     * holds a tag not sent, is not nested as sent, leaves out a link, or loses one at the line's edge. Kept to
     * one line, trimmed at its edges.
     *
     * @param  list<string>  $links  the addresses `encode()` gave, by index
     * @return list<array<string, mixed>>|null
     */
    public static function parse(string $answer, array $links): ?array
    {
        $parts = preg_split(self::PARTS, $answer, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return null;
        }

        $segments = [];
        $bold = false;
        $boldHasWords = false;
        $link = null;
        $linkHasWords = false;

        foreach ($parts as $part) {
            if ($part === '<b>') {
                if ($bold) {
                    return null;
                }

                [$bold, $boldHasWords] = [true, false];

                continue;
            }

            if ($part === '</b>') {
                if (! $bold || ! $boldHasWords) {
                    return null;
                }

                $bold = false;

                continue;
            }

            if (preg_match(self::LINK_OPEN, $part, $found) === 1) {
                $index = (int) $found[1];

                // Never inside a bold or another link, never an index not sent.
                if ($bold || $link !== null || ! isset($links[$index])) {
                    return null;
                }

                [$link, $linkHasWords] = [$index, false];

                continue;
            }

            if ($part === '</a>') {
                // A bold still open inside the link is not nested as sent.
                if ($link === null || $bold || ! $linkHasWords) {
                    return null;
                }

                $link = null;

                continue;
            }

            $words = self::words($part);

            if ($words === null) {
                return null;
            }

            $segments[] = ['text' => $words, 'bold' => $bold, 'link' => $link];
            $boldHasWords = $boldHasWords || $bold;
            $linkHasWords = $linkHasWords || $link !== null;
        }

        if ($bold || $link !== null) {
            return null;
        }

        return self::runs($segments, $links);
    }

    /**
     * The answer as plain words, its marks dropped (§1.3 (b)), for a box whose answer `parse()` refused or
     * which held no marks: every tag taken out, then XML's entities read. What a tag's text was becomes
     * words, never a mark.
     */
    public static function plain(string $answer): string
    {
        $words = (string) preg_replace('/<[^<>]*>/', '', $answer);

        return html_entity_decode($words, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A part of the answer that is not a tag: its words, or null when it is not XML text. */
    private static function words(string $part): ?string
    {
        if (str_starts_with($part, '&')) {
            return self::ENTITIES[$part] ?? self::character($part);
        }

        // A tag not sent, or a "<" or "&" left bare.
        return str_contains($part, '<') || str_contains($part, '&') ? null : $part;
    }

    /** A numeric character reference (`&#1576;`, `&#x628;`) as its character, or null. */
    private static function character(string $part): ?string
    {
        if (preg_match('/\A&#([0-9]{1,7});\z/', $part, $found) === 1) {
            $code = (int) $found[1];
        } elseif (preg_match('/\A&#x([0-9a-fA-F]{1,6});\z/', $part, $found) === 1) {
            $code = (int) hexdec($found[1]);
        } else {
            return null;
        }

        $character = $code > 0 ? mb_chr($code, 'UTF-8') : false;

        return $character === false ? null : $character;
    }

    /**
     * The segments as runs on one line: line breaks as spaces, the line's edges trimmed, each link's
     * address by its index. Null for a line of no words, for words that are not UTF-8, and for a line that
     * lost a link sent - left out, or holding only spaces at an edge that is trimmed away.
     *
     * @param  list<array{text: string, bold: bool, link: int|null}>  $segments
     * @param  list<string>  $links
     * @return list<array<string, mixed>>|null
     */
    private static function runs(array $segments, array $links): ?array
    {
        $kept = [];

        foreach ($segments as $segment) {
            $text = preg_replace(self::LINE_BREAK, ' ', $segment['text']);

            // Words that are not UTF-8: no answer for the marks.
            if ($text === null) {
                return null;
            }

            $kept[] = ['text' => $text, 'bold' => $segment['bold'], 'link' => $segment['link']];
        }

        // The line's edges trimmed, as every piece is: a segment left with nothing is dropped. Each turn takes
        // one off or stops, so neither loop outlasts the segments.
        while ($kept !== [] && ltrim($kept[0]['text']) === '') {
            array_shift($kept);
        }

        while ($kept !== [] && rtrim($kept[count($kept) - 1]['text']) === '') {
            array_pop($kept);
        }

        if ($kept === []) {
            return null;
        }

        // Every link sent is still there, at least once.
        $present = array_unique(array_filter(array_column($kept, 'link'), is_int(...)));

        if (count($present) !== count($links)) {
            return null;
        }

        $runs = [];
        $last = count($kept) - 1;

        foreach ($kept as $at => $segment) {
            $text = $at === 0 ? ltrim($segment['text']) : $segment['text'];
            $run = ['text' => $at === $last ? rtrim($text) : $text];

            if ($segment['bold']) {
                $run['bold'] = true;
            }

            if ($segment['link'] !== null) {
                $run['link'] = $links[$segment['link']];
            }

            $runs[] = $run;
        }

        return $runs;
    }
}

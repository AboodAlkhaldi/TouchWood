<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use LogicException;

/**
 * The text an mPDF file draws, for the daily report's tests: the page content streams inflated, and each
 * string shown (`(...) Tj`, or inside a `[...] TJ`) unescaped and read as UTF-16BE - which is how mPDF
 * writes text in its TrueType fonts (Identity-H, the code being the character). Latin letters, digits and
 * "{" read back as themselves; shaped Arabic reads as the font's own presentation codes, which these tests
 * do not look at.
 */
final class PdfText
{
    public static function of(string $pdf): string
    {
        if (preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams, PREG_SET_ORDER) === 0) {
            throw new LogicException('No stream in the PDF.');
        }

        $text = '';
        foreach ($streams as [, $dictionary, $body]) {
            $content = str_contains($dictionary, '/FlateDecode') ? @gzuncompress($body) : $body;
            if (! is_string($content) || ! str_contains($content, 'BT')) {
                continue;
            }
            preg_match_all('/\((?:[^\\\\)]|\\\\.)*\)/s', $content, $strings);
            foreach ($strings[0] as $string) {
                $raw = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', static fn (array $m): string => ctype_digit($m[1]) ? chr((int) octdec($m[1])) : match ($m[1]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", default => $m[1],
                }, substr($string, 1, -1)) ?? '';
                $text .= (strlen($raw) % 2 === 0 ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : $raw);
            }
        }

        return $text;
    }
}

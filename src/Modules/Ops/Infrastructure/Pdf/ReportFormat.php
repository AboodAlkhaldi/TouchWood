<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Pdf;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\HtmlString;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\Money;

/**
 * How the daily report's view writes a number, an amount or a time: Latin digits always (owner,
 * 2026-10-06), each isolated left to right in a `<bdi dir="ltr">` - mPDF's bidi otherwise reorders
 * "2026-10-29" inside Arabic text (the library test of 2026-10-29). A `<span dir="ltr">` does not do
 * it: mPDF reads `dir` on `bdo` and `bdi` only (vendor/mpdf/mpdf/src/Tag/InlineTag.php).
 */
final readonly class ReportFormat
{
    public function __construct(private string $locale) {}

    public function int(int $value): HtmlString
    {
        return self::ltr(number_format($value));
    }

    /** An amount with its currency's letters after it (ر.س, SAR). */
    public function money(Money $amount, int $exponent, string $symbol): HtmlString
    {
        return new HtmlString(self::ltr($amount->format($exponent))->toHtml().' '.e($symbol));
    }

    public function storeMoney(Money $amount, StoreDto $store): HtmlString
    {
        return $this->money($amount, $store->currencyExponent, self::letters($store, $this->locale));
    }

    /** An amount divided by a count, rounded down to the currency's smallest unit; a dash for none. */
    public function average(Money $total, int $count, StoreDto $store): HtmlString
    {
        if ($count === 0) {
            return new HtmlString('—');
        }

        return $this->storeMoney(Money::of(intdiv($total->minorUnits, $count), $total->currencyCode), $store);
    }

    /** Today against yesterday as a signed percentage; a dash when yesterday had none. */
    public function change(int $today, int $yesterday): HtmlString
    {
        if ($yesterday === 0) {
            return new HtmlString('—');
        }
        // Over yesterday's size, whatever its sign: revenue net of refunds may be below zero.
        $percent = (int) round(($today - $yesterday) * 100 / abs($yesterday));

        return self::ltr(($percent > 0 ? '+' : '').$percent.'%');
    }

    public function date(DateTimeImmutable $moment, string $zone): HtmlString
    {
        return self::ltr($moment->setTimezone(new DateTimeZone($zone))->format('Y-m-d'));
    }

    public function time(DateTimeImmutable $moment, string $zone): HtmlString
    {
        return self::ltr($moment->setTimezone(new DateTimeZone($zone))->format('H:i'));
    }

    /**
     * The currency's letters, never its sign: the new signs (the riyal's U+20C1, the dirham's U+20C3)
     * are in no font mPDF ships, and would print as an empty box.
     */
    public static function letters(StoreDto $store, string $locale): string
    {
        return $store->currencyAbbreviation->in($locale);
    }

    /** Text that reads left to right whatever the page's direction: a code, a time zone. */
    public static function ltr(string $text): HtmlString
    {
        return new HtmlString('<bdi dir="ltr">'.self::inert(e($text)).'</bdi>');
    }

    public function text(string $ar, string $en): string
    {
        return $this->locale === 'en' && $en !== '' ? $en : $ar;
    }

    /**
     * Text people wrote - a search phrase, a product's, a size's, a category's or a store's name - escaped,
     * and with mPDF's placeholders made inert: mPDF writes the page number over "{PAGENO}" and the page
     * count over "{nb}" and "{nbpg}" wherever they stand in a page's text (Mpdf::aliasReplace), body
     * included.
     */
    public static function safe(string $text): HtmlString
    {
        return new HtmlString(self::inert(e($text)));
    }

    /** The text with every "{" followed by a word joiner, so no mPDF placeholder matches it. */
    /**
     * Escaped HTML with each "{" in a run of its own: mPDF matches its placeholders inside one run of text,
     * so "{" drawn apart (a span it cannot merge with the next: its own letter spacing) never makes one -
     * and it is still the font's own "{", read and copied as typed. A character of no glyph (a word joiner,
     * U+2060, was tried) would print as a box: IBM Plex Sans Arabic has none.
     */
    public static function inert(string $html): string
    {
        return str_replace('{', '<span style="letter-spacing: 0.01pt">{</span>', $html);
    }
}

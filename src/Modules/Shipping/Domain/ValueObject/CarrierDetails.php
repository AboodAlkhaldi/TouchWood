<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\ValueObject;

use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\Text\PhoneNumbers;

/**
 * What a carrier says of itself (shipping.md §1.3, owner 2026-10-27 S11): its name in both languages
 * (1–100 each, both required), and optionally a phone (E.164, the rule every module shares — step 3
 * Q2), a website and a tracking link pattern (HTTPS, at most 500 characters — Q6; the pattern with
 * exactly one `{number}`). Digits are kept Latin (catalog.md amendment 12); empty means none.
 */
final readonly class CarrierDetails
{
    public const int NAME_MAX = 100;

    public const int LINK_MAX = 500;

    public const string NUMBER = '{number}';

    private function __construct(
        public string $nameAr,
        public string $nameEn,
        public ?string $phone,
        public ?string $website,
        public ?string $trackingPattern,
    ) {}

    /**
     * @throws InvalidShippingInput
     */
    public static function of(string $nameAr, string $nameEn, ?string $phone, ?string $website, ?string $trackingPattern): self
    {
        return new self(
            self::name('name_ar', $nameAr),
            self::name('name_en', $nameEn),
            self::phone($phone),
            self::website($website),
            self::trackingPattern($trackingPattern),
        );
    }

    /**
     * As the database keeps them, already checked.
     */
    public static function reconstitute(string $nameAr, string $nameEn, ?string $phone, ?string $website, ?string $trackingPattern): self
    {
        return new self($nameAr, $nameEn, $phone, $website, $trackingPattern);
    }

    private static function name(string $field, string $value): string
    {
        $name = LatinDigits::of(trim($value));
        $length = mb_strlen($name);

        if ($length < 1 || $length > self::NAME_MAX) {
            throw new InvalidShippingInput($field, '1-'.self::NAME_MAX.' characters');
        }

        return $name;
    }

    private static function phone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return PhoneNumbers::normalize($value) ?? throw new InvalidShippingInput('phone', 'an international number such as +966501234567');
    }

    private static function website(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $link = trim($value);
        self::https('website', $link);

        return $link;
    }

    private static function trackingPattern(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $pattern = trim($value);

        if (substr_count($pattern, self::NUMBER) !== 1) {
            throw new InvalidShippingInput('tracking_pattern', 'tracking_pattern_number');
        }

        // Checked as the link it makes: a number in the place of {number}.
        self::https('tracking_pattern', $pattern, str_replace(self::NUMBER, '0', $pattern));

        return $pattern;
    }

    private static function https(string $field, string $kept, ?string $asLink = null): void
    {
        $link = $asLink ?? $kept;

        if (mb_strlen($kept) > self::LINK_MAX) {
            throw new InvalidShippingInput($field, 'at most '.self::LINK_MAX.' characters');
        }

        if (filter_var($link, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($link, PHP_URL_SCHEME)) !== 'https' || (string) parse_url($link, PHP_URL_HOST) === '') {
            throw new InvalidShippingInput($field, 'https');
        }
    }
}

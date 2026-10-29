<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\ValueObject;

use Modules\Ops\Domain\Exception\InvalidOpsInput;

/**
 * What staff write to customers (ops.md §1.7, §1.8): a title and a line in both languages - the bell's
 * own two, and the email's subject and text - and, for a notice, an optional page of the shop.
 *
 * The link is a page **of the shop**, written without its store and language ("categories/chairs"):
 * each customer gets it in their own language, under the store's address. Nothing that could leave
 * the site is taken - no scheme, no "//", no backslash.
 *
 * Every rule of the tables' CHECKs is refused here first (conventions: code catches mistakes before
 * the database).
 */
final readonly class WrittenText
{
    public const int TITLE_MAX = 200;

    /** The bell's line holds 500 (InboxEntry::LINE_MAX): the text is that line. */
    public const int BODY_MAX = 500;

    public const int LINK_MAX = 500;

    private function __construct(
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr,
        public string $bodyEn,
        public ?string $link,
    ) {}

    /** @throws InvalidOpsInput */
    public static function of(string $titleAr, string $titleEn, string $bodyAr, string $bodyEn, ?string $link = null): self
    {
        $texts = [
            'title_ar' => [trim($titleAr), self::TITLE_MAX],
            'title_en' => [trim($titleEn), self::TITLE_MAX],
            'body_ar' => [trim($bodyAr), self::BODY_MAX],
            'body_en' => [trim($bodyEn), self::BODY_MAX],
        ];

        foreach ($texts as $field => [$text, $max]) {
            $length = mb_strlen($text);

            if ($length < 1 || $length > $max) {
                throw new InvalidOpsInput($field, "between 1 and {$max} characters");
            }

            // A title is one line; a text may hold line breaks, but no other control character.
            $controls = str_starts_with($field, 'title') ? '/[\x00-\x1F\x7F]/u' : '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u';

            if (preg_match($controls, $text) !== 0) {
                throw new InvalidOpsInput($field, 'no control characters');
            }
        }

        return new self($texts['title_ar'][0], $texts['title_en'][0], $texts['body_ar'][0], $texts['body_en'][0], self::link($link));
    }

    /** @throws InvalidOpsInput */
    private static function link(?string $link): ?string
    {
        if ($link === null || trim($link) === '') {
            return null;
        }

        $link = ltrim(trim($link), '/');

        if ($link === '' || mb_strlen($link) > self::LINK_MAX || str_contains($link, '//') || str_contains($link, '\\')
            || str_contains($link, ':') || preg_match('/[\s\x00-\x1F\x7F]/u', $link) !== 0) {
            throw new InvalidOpsInput('link', 'a page of the shop');
        }

        return $link;
    }
}

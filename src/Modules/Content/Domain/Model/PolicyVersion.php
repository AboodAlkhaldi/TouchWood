<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;
use Modules\Content\Domain\ValueObject\RichText;

/**
 * One published text of a policy, kept for good (flags-2026-11.md S3.2, F14): its number (1, 2, 3...), both languages'
 * title, body and the body's images by ref, and when and by whom it was published - so the exact text a customer
 * accepted can always be shown to staff.
 */
final readonly class PolicyVersion
{
    /**
     * @param  array<string, array{title: string, body: RichText, images: array<int, array{media: string, alt: string}>}>  $texts  by language, `ar` and `en`
     */
    public function __construct(
        public string $policyId,
        public int $number,
        public array $texts,
        public DateTimeImmutable $publishedAt,
        public ?string $publishedBy,
    ) {}

    /** The policy's text as it is now, kept as this number. */
    public static function of(Policy $policy, int $number, DateTimeImmutable $at, ?string $by): self
    {
        $texts = [];

        foreach ($policy->texts as $locale => $text) {
            $texts[$locale] = ['title' => $text->title, 'body' => $text->body, 'images' => $text->images];
        }

        return new self($policy->id, $number, $texts, $at, $by);
    }

    /**
     * Whether the policy's text now is this version's: each language's title, body and the body's images the same.
     * An address or an SEO box is not the text a customer reads and accepts.
     */
    public function sameTextAs(Policy $policy): bool
    {
        if (array_keys($this->texts) !== array_keys($policy->texts)) {
            return false;
        }

        foreach ($this->texts as $locale => $kept) {
            $now = $policy->texts[$locale];

            if ($kept['title'] !== $now->title || ! $kept['body']->equals($now->body) || self::byRef($kept['images']) !== self::byRef($now->images)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Image rows in ref order, so the same rows read from the database and from a form compare equal.
     *
     * @param  array<int, array{media: string, alt: string}>  $images
     * @return array<int, array{media: string, alt: string}>
     */
    private static function byRef(array $images): array
    {
        ksort($images);

        return $images;
    }
}

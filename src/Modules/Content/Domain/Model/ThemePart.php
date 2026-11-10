<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * One filled part of a theme (content.md §1.8): its words per language with an optional link, its extra words (the
 * promises row's words for each promise), and its images by slot and language (`any`, `ar`, `en`).
 */
final readonly class ThemePart
{
    /**
     * @param  array<string, array{ar: string, en: string|null}>  $extras  words only, by key (`promise_1`...)
     * @param  array<string, string>  $images  `{slot}:{locale}` => media id
     */
    public function __construct(
        public ThemePartKind $part,
        public ?string $textAr,
        public ?string $textEn,
        public ?string $link,
        public array $extras,
        public array $images,
    ) {}

    /** Nothing in it: left empty, it looks as the normal site (Q7 (a)). */
    public function isEmpty(): bool
    {
        return $this->textAr === null && $this->extras === [] && $this->images === [];
    }
}

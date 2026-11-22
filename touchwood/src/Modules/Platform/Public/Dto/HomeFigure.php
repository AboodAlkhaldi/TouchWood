<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * One number on a home card (platform.md §2.6): what it is, read from the card's words, and how
 * much. The page writes it in its own digits, a size as a size.
 */
final readonly class HomeFigure
{
    public const string COUNT = 'count';

    public const string BYTES = 'bytes';

    /**
     * @param  string  $label  the key of its words, under the card's own: `{module}::home.{key}.{label}`
     * @param  string  $unit  self::COUNT or self::BYTES
     * @param  string|null  $href  where it leads, when there is a screen for it
     * @param  string|null  $tone  a Geist tone (e.g. `amber`) for a number that asks for attention
     */
    public function __construct(
        public string $label,
        public int $value,
        public string $unit = self::COUNT,
        public ?string $href = null,
        public ?string $tone = null,
    ) {}
}

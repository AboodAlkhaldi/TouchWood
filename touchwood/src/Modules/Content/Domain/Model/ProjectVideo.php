<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * One of a project's YouTube videos (flags-2026-11.md S4.4, content.md §1.13, amendment 4): the video's 11-character
 * id - the link it was pasted as is not kept - and its title per language, each optional (`Words::pair`: Arabic first,
 * English to publish).
 */
final readonly class ProjectVideo
{
    public function __construct(
        public string $videoId,
        public ?string $titleAr,
        public ?string $titleEn,
    ) {}
}

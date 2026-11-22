<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetProductVideos;

/**
 * A product's YouTube videos, sent whole and in order (flags-2026-11.md S4.4, catalog.md amendment 48): at most 10,
 * each a link with an optional title per language.
 */
final readonly class SetProductVideos
{
    /**
     * @param  array<array-key, mixed>  $videos  each `['link' => …, 'title_ar' => …, 'title_en' => …]`, in order
     */
    public function __construct(
        public string $productId,
        public array $videos,
    ) {}
}

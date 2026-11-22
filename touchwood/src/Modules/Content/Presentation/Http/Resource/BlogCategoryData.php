<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of the blog's categories (content.md §1.12, W8): its name and address per language, and how many posts it holds. */
#[TypeScript]
final class BlogCategoryData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public string $slugAr,
        public string $slugEn,
        public int $posts,
    ) {}

    /**
     * @param  array<string, mixed>  $row  ScreenReads::blogCategories()'s row
     */
    public static function fromRow(array $row): self
    {
        $text = static fn (string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

        return new self($text('id'), $text('nameAr'), $text('nameEn'), $text('slugAr'), $text('slugEn'), (int) ($row['posts'] ?? 0));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One page, post or project on W7-W9's lists (content.md §4.4): its title and address per language, state, when it was
 * published and last changed, by whom; a post's category.
 */
#[TypeScript]
final class DocumentRowData extends Data
{
    public function __construct(
        public string $id,
        public string $status,
        public string $titleAr,
        public ?string $titleEn,
        public string $addressAr,
        public ?string $addressEn,
        public ?string $categoryId,
        public ?string $publishedAt,
        public ?string $changedAt,
        public ?string $changedBy,
        public int $version,
    ) {}

    /**
     * @param  array<string, mixed>  $row  DocumentsHandler::list()'s row
     */
    public static function fromRow(array $row): self
    {
        $text = static fn (string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;

        return new self((string) $text('id'), (string) $text('status'), (string) $text('titleAr'), $text('titleEn'), (string) $text('addressAr'), $text('addressEn'), $text('categoryId'), $text('publishedAt'), $text('changedAt'), $text('changedBy'), (int) ($row['version'] ?? 0));
    }
}
